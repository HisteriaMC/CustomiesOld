<?php
declare(strict_types=1);

namespace customiesdevs\customies\block;

use Closure;
use customiesdevs\customies\block\permutations\Permutable;
use customiesdevs\customies\block\permutations\Permutation;
use customiesdevs\customies\block\permutations\Permutations;
use customiesdevs\customies\item\CreativeInventoryInfo;
use customiesdevs\customies\item\CustomiesItemFactory;
use customiesdevs\customies\task\AsyncRegisterBlocksTask;
use customiesdevs\customies\util\Cache;
use customiesdevs\customies\util\NBT;
use InvalidArgumentException;
use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\inventory\CreativeInventory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\Server;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use function array_map;
use function array_reverse;
use function get_class;

final class CustomiesBlockFactory {
	use SingletonTrait;

	/**
	 * @var Closure[]
	 * @phpstan-var array<string, Closure(int): Block>
	 */
	private array $blockFuncs = [];
	/** @var BlockPaletteEntry[] */
	private array $blockPaletteEntries = [];
	/** @var array<string, int> */
	private array $stringIdToTypedIds = [];

	/**
	 * Adds a worker initialize hook to the async pool to sync the BlockFactory for every thread worker that is created.
	 * It is especially important for the workers that deal with chunk encoding, as using the wrong runtime ID mappings
	 * can result in massive issues with almost every block showing as the wrong thing and causing lag to clients.
	 */
	public function addWorkerInitHook(string $cachePath): void {
		$server = Server::getInstance();
		$blocks = $this->blockFuncs;
		$server->getAsyncPool()->addWorkerStartHook(static function (int $worker) use ($cachePath, $server, $blocks): void {
			$server->getAsyncPool()->submitTaskToWorker(new AsyncRegisterBlocksTask($cachePath, $blocks), $worker);
		});
	}

	/**
	 * Get a custom block from its identifier. An exception will be thrown if the block is not registered.
	 */
	public function get(string $identifier): Block {
		return RuntimeBlockStateRegistry::getInstance()->fromTypeId(
			$this->stringIdToTypedIds[$identifier] ??
			throw new InvalidArgumentException("Custom block " . $identifier . " is not registered")
		);
	}

	/**
	 * Returns all the block palette entries that need to be sent to the client.
	 * @return BlockPaletteEntry[]
	 */
	public function getBlockPaletteEntries(): array {
		return $this->blockPaletteEntries;
	}

	/**
	 * Register a block to the BlockFactory and all the required mappings.
	 * @phpstan-param (Closure(int): Block) $blockFunc
	 */
	public function registerBlock(Closure $blockFunc, string $identifier, ?Model $model = null, ?CreativeInventoryInfo $creativeInfo = null, ?Closure $objectToState = null, ?Closure $stateToObject = null): void {
		$id = $this->getNextAvailableId($identifier);
		$block = $blockFunc($id);
		if(!$block instanceof Block) {
			throw new InvalidArgumentException("Class returned from closure is not a Block");
		}

		if(RuntimeBlockStateRegistry::getInstance()->isRegistered($id)) {
			throw new InvalidArgumentException("Block with ID " . $id . " is already registered");
		}
		RuntimeBlockStateRegistry::getInstance()->register($block);
		CustomiesItemFactory::getInstance()->registerBlockItem($identifier, $block);
		$this->stringIdToTypedIds[$identifier] = $id;

		$propertiesTag = CompoundTag::create();
		$components = CompoundTag::create()
			->setTag("minecraft:light_emission", CompoundTag::create()
				->setByte("emission", $block->getLightLevel()))
			->setTag("minecraft:block_light_filter", CompoundTag::create()
				->setByte("lightLevel", $block->getLightFilter()))
			->setTag("minecraft:destructible_by_mining", CompoundTag::create()
				->setFloat("value", $block->getBreakInfo()->getHardness()))
			->setTag("minecraft:destructible_by_explosion", CompoundTag::create()
				->setFloat("value", $block->getBreakInfo()->getBlastResistance()))
			->setTag("minecraft:friction", CompoundTag::create()
				->setFloat("value", $block->getFrictionFactor()))
			->setTag("minecraft:flammable", CompoundTag::create()
				->setInt("catch_chance_modifier", $block->getFlameEncouragement())
				->setInt("destroy_chance_modifier", $block->getFlammability()));

		if($model !== null) {
			foreach($model->toNBT() as $tagName => $tag){
				$components->setTag($tagName, $tag);
			}
		}

		if($block instanceof Permutable) {
			$blockPropertyNames = $blockPropertyValues = $blockProperties = [];
			foreach($block->getBlockProperties() as $blockProperty){
				$blockPropertyNames[] = $blockProperty->getName();
				$blockPropertyValues[] = $blockProperty->getValues();
				$blockProperties[] = $blockProperty->toNBT();
			}
			$permutations = array_map(static fn(Permutation $permutation) => $permutation->toNBT(), $block->getPermutations());

			// The 'minecraft:on_player_placing' component is required for the client to predict block placement, making
			// it a smoother experience for the end-user.
			$components->setTag("minecraft:on_player_placing", CompoundTag::create());
			$propertiesTag
				->setTag("permutations", new ListTag($permutations))
				->setTag("properties", new ListTag(array_reverse($blockProperties))); // fix client-side order

			foreach(Permutations::getCartesianProduct($blockPropertyValues) as $meta => $permutations){
				// We need to insert states for every possible permutation to allow for all blocks to be used and to
				// keep in sync with the client's block palette.
				$states = CompoundTag::create();
				foreach($permutations as $i => $value){
					$states->setTag($blockPropertyNames[$i], NBT::getTagType($value));
				}
				$blockState = CompoundTag::create()
					->setString("name", $identifier)
					->setTag("states", $states);
				BlockPalette::getInstance()->insertState($blockState, $meta);
			}

			GlobalBlockStateHandlers::getSerializer()->map($block, $objectToState ?? throw new InvalidArgumentException("Serializer for " . get_class($block) . " cannot be null"));
			GlobalBlockStateHandlers::getDeserializer()->map($identifier, $stateToObject ?? throw new InvalidArgumentException("Deserializer for " . get_class($block) . " cannot be null"));
		} else {
			// If a block does not contain any permutations we can just insert the one state.
			$blockState = CompoundTag::create()
				->setString("name", $identifier)
				->setTag("states", CompoundTag::create());
			BlockPalette::getInstance()->insertState($blockState);
			GlobalBlockStateHandlers::getSerializer()->map($block, $objectToState ??= static fn() => new BlockStateWriter($identifier));
			GlobalBlockStateHandlers::getDeserializer()->map($identifier, $stateToObject ??= static fn(BlockStateReader $in) => $block);
		}

		$creativeInfo ??= CreativeInventoryInfo::DEFAULT();
		$components->setTag("minecraft:creative_category", CompoundTag::create()
			->setString("category", $creativeInfo->getCategory())
			->setString("group", $creativeInfo->getGroup()));
		$propertiesTag
			->setTag("components",
				$components->setTag("minecraft:creative_category", CompoundTag::create()
					->setString("category", $creativeInfo->getCategory())
					->setString("group", $creativeInfo->getGroup())))
			->setTag("menu_category", CompoundTag::create()
				->setString("category", $creativeInfo->getCategory() ?? "")
				->setString("group", $creativeInfo->getGroup() ?? ""))
			->setInt("molangVersion", 1);

		CreativeInventory::getInstance()->add($block->asItem());

		$this->blockPaletteEntries[] = new BlockPaletteEntry($identifier, new CacheableNbt($propertiesTag));
		$this->blockFuncs[$identifier] = [$blockFunc, $objectToState, $stateToObject];
	}

	/**
	 * Returns the next available custom block id, an exception will be thrown if the block factory is full.
	 */
	private function getNextAvailableId(string $identifier): int {
		return Cache::getInstance()->getNextAvailableBlockID($identifier);
	}
}
