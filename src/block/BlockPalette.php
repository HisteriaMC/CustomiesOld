<?php
declare(strict_types=1);

namespace customiesdevs\customies\block;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use ReflectionProperty;
use RuntimeException;
use function array_keys;
use function array_map;
use function hash;
use function strcmp;
use function usort;

final class BlockPalette {
	use SingletonTrait;

	/** @var BlockStateDictionaryEntry[] */
	private array $states;
	/** @var BlockStateDictionaryEntry[] */
	private array $customStates = [];

	private BlockStateDictionary $dictionary;
	private ReflectionProperty $bedrockKnownStates;
	private ReflectionProperty $lookupCache;

	public function __construct() {
		$instance = TypeConverter::getInstance()->getBlockTranslator();
		$this->dictionary = $dictionary = $instance->getBlockStateDictionary();
		$this->states = $dictionary->getStates();

		$this->bedrockKnownStates = $bedrockKnownStates = new ReflectionProperty($dictionary, "states");
		$bedrockKnownStates->setAccessible(true);
		$this->lookupCache = $lookupCache = new ReflectionProperty($dictionary, "stateDataToStateIdLookup");
		$lookupCache->setAccessible(true);
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getStates(): array {
		return $this->states;
	}

	/**
	 * @return BlockStateDictionaryEntry[]
	 */
	public function getCustomStates(): array {
		return $this->customStates;
	}

	/**
	 * Inserts the provided state in to the correct position of the palette.
	 */
	public function insertState(CompoundTag $state, int $meta = 0): void {
		if($state->getString("name") === "") {
			throw new RuntimeException("Block state must contain a StringTag called 'name'");
		}
		if($state->getCompoundTag("states") === null) {
			throw new RuntimeException("Block state must contain a CompoundTag called 'states'");
		}
        $stateData = BlockStateData::fromNbt($state);
        $this->sortWith($entry = new BlockStateDictionaryEntry($stateData->getName(), $stateData->getStates(), $meta));
		$this->customStates[] = $entry;
	}

	/**
	 * Sorts the palette's block states in the correct order, also adding the provided state to the array.
	 */
	private function sortWith(BlockStateDictionaryEntry $newState): void {
		// To sort the block palette we first have to split the palette up in to groups of states. We only want to sort
		// using the name of the block, and keeping the order of the existing states.
		$states = [];
		foreach($this->getStates() as $state){
			$states[$state->getStateName()][] = $state;
		}
		// Append the new state we are sorting with at the end to preserve existing order.
		$states[$newState->getStateName()][] = $newState;

		$names = array_keys($states);
		// As of 1.18.30, blocks are sorted using a fnv164 hash of their names.
		usort($names, static fn(string $a, string $b) => strcmp(hash("fnv164", $a), hash("fnv164", $b)));
		$sortedStates = [];
		foreach($names as $name){
			// With the sorted list of names, we can now go back and add all the states for each block in the correct order.
			foreach($states[$name] as $state){
				$sortedStates[] = $state;
			}
		}
		$this->states = $sortedStates;
		$this->bedrockKnownStates->setValue($this->dictionary, $sortedStates);

        $table = [];
        foreach($this->states as $stateId => $stateNbt){
            $table[$stateNbt->getStateName()][$stateNbt->getRawStateProperties()] = $stateId;
        }

        $stateDataToStateIdLookup = [];
        //setup fast path for stateless blocks
        foreach(Utils::stringifyKeys($table) as $name => $stateIds){
            if(count($stateIds) === 1){
                $stateDataToStateIdLookup[$name] = $stateIds[array_key_first($stateIds)];
            }else{
                $stateDataToStateIdLookup[$name] = $stateIds;
            }
        }
		$this->lookupCache->setValue(
			$this->dictionary,
            $stateDataToStateIdLookup
		);
	}
}
