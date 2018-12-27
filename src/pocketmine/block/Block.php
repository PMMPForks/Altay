<?php

/*
 *               _ _
 *         /\   | | |
 *        /  \  | | |_ __ _ _   _
 *       / /\ \ | | __/ _` | | | |
 *      / ____ \| | || (_| | |_| |
 *     /_/    \_|_|\__\__,_|\__, |
 *                           __/ |
 *                          |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author TuranicTeam
 * @link https://github.com/TuranicTeam/Altay
 *
 */

declare(strict_types=1);

/**
 * All Block classes are in here
 */
namespace pocketmine\block;

use pocketmine\entity\Entity;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\metadata\Metadatable;
use pocketmine\metadata\MetadataValue;
use pocketmine\Player;
use pocketmine\plugin\Plugin;

class Block extends Position implements BlockIds, Metadatable{

	/**
	 * Returns a new Block instance with the specified ID, meta and position.
	 *
	 * This function redirects to {@link BlockFactory#get}.
	 *
	 * @param int           $id
	 * @param int           $meta
	 * @param Position|null $pos
	 *
	 * @return Block
	 */
	public static function get(int $id, int $meta = 0, Position $pos = null) : Block{
		return BlockFactory::get($id, $meta, $pos);
	}

	/** @var int */
	protected $id;
	/** @var int */
	protected $variant = 0;
	/** @var string|null */
	protected $fallbackName;
	/** @var int|null */
	protected $itemId;

	/** @var AxisAlignedBB */
	protected $boundingBox = null;


	/** @var AxisAlignedBB[]|null */
	protected $collisionBoxes = null;

	/**
	 * @param int         $id      The block type's ID, 0-255
	 * @param int         $variant Meta value of the block type
	 * @param string|null $name    English name of the block type (TODO: implement translations)
	 * @param int         $itemId  The item ID of the block type, used for block picking and dropping items.
	 */
	public function __construct(int $id, int $variant = 0, string $name = null, int $itemId = null){
		$this->id = $id;

		if(($variant & $this->getStateBitmask()) !== 0){
			throw new \InvalidArgumentException("Variant 0x" . dechex($variant) . " collides with state bitmask 0x" . dechex($this->getStateBitmask()));
		}
		$this->variant = $variant;
		$this->fallbackName = $name;
		$this->itemId = $itemId;
	}

	/**
	 * @return string
	 */
	public function getName() : string{
		return $this->fallbackName ?? "Unknown";
	}

	/**
	 * @return int
	 */
	public function getId() : int{
		return $this->id;
	}

	/**
	 * Returns the ID of the item form of the block.
	 * Used for drops for blocks (some blocks such as doors have a different item ID).
	 *
	 * @return int
	 */
	public function getItemId() : int{
		if($this->itemId !== null){
			return $this->itemId;
		}
		if($this->id > 255){
			return 255 - $this->id;
		}
		return $this->id;
	}

	public function getItem() : Item{
		return ItemFactory::get($this->getItemId(), $this->getVariant());
	}

	/**
	 * @internal
	 * @return int
	 */
	public function getRuntimeId() : int{
		return BlockFactory::toStaticRuntimeId($this->getId(), $this->getDamage());
	}

	/**
	 * @return int
	 */
	public function getDamage() : int{
		$stateMeta = $this->writeStateToMeta();
		assert(($stateMeta & ~$this->getStateBitmask()) === 0);
		return $this->variant | $stateMeta;
	}

	protected function writeStateToMeta() : int{
		return 0;
	}

	public function readStateFromMeta(int $meta) : void{
		//NOOP
	}

	/**
	 * Called when this block is created, set, or has a neighbouring block update, to re-detect dynamic properties which
	 * are not saved on the world.
	 *
	 * Clears any cached precomputed objects, such as bounding boxes. Remove any outdated precomputed things such as
	 * AABBs and force recalculation.
	 */
	public function readStateFromWorld() : void{
		$this->boundingBox = null;
		$this->collisionBoxes = null;
	}

	public function writeStateToWorld() : void{
		$this->level->getChunkAtPosition($this)->setBlock($this->x & 0xf, $this->y, $this->z & 0xf, $this->getId(), $this->getDamage());
	}

	/**
	 * Returns a bitmask used to extract state bits from block metadata.
	 *
	 * @return int
	 */
	public function getStateBitmask() : int{
		return 0;
	}

	/**
	 * Returns the block meta, stripped of non-variant flags.
	 * @return int
	 */
	public function getVariant() : int{
		return $this->variant;
	}

	/**
	 * Returns whether the given block has an equivalent type to this one.
	 *
	 * @param Block $other
	 *
	 * @return bool
	 */
	public function isSameType(Block $other) : bool{
		return $this->getId() === $other->getId() and $this->getVariant() === $other->getVariant();
	}

	/**
	 * Returns whether the given block has the same type and properties as this block.
	 *
	 * @param Block $other
	 *
	 * @return bool
	 */
	public function isSameState(Block $other) : bool{
		return $this->isSameType($other) and $this->writeStateToMeta() === $other->writeStateToMeta();
	}

	/**
	 * AKA: Block->isPlaceable
	 * @return bool
	 */
	public function canBePlaced() : bool{
		return true;
	}

	/**
	 * @return bool
	 */
	public function canBeReplaced() : bool{
		return false;
	}

	public function canBePlacedAt(Block $blockReplace, Vector3 $clickVector, int $face, bool $isClickedBlock) : bool{
		return $blockReplace->canBeReplaced();
	}

	/**
	 * Places the Block, using block space and block target, and side. Returns if the block has been placed.
	 *
	 * @param Item        $item
	 * @param Block       $blockReplace
	 * @param Block       $blockClicked
	 * @param int         $face
	 * @param Vector3     $clickVector
	 * @param Player|null $player
	 *
	 * @return bool
	 */
	public function place(Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, Player $player = null) : bool{
		return $this->getLevel()->setBlock($blockReplace, $this);
	}

	/**
	 * Returns if the block can be broken with an specific Item
	 *
	 * @param Item $item
	 *
	 * @return bool
	 */
	public function isBreakable(Item $item) : bool{
		return true;
	}

	/**
	 * @return int
	 */
	public function getToolType() : int{
		return BlockToolType::TYPE_NONE;
	}

	/**
	 * Returns the level of tool required to harvest this block (for normal blocks). When the tool type matches the
	 * block's required tool type, the tool must have a harvest level greater than or equal to this value to be able to
	 * successfully harvest the block.
	 *
	 * If the block requires a specific minimum tier of tiered tool, the minimum tier required should be returned.
	 * Otherwise, 1 should be returned if a tool is required, 0 if not.
	 *
	 * @see Item::getBlockToolHarvestLevel()
	 *
	 * @return int
	 */
	public function getToolHarvestLevel() : int{
		return 0;
	}

	/**
	 * Returns whether the specified item is the proper tool to use for breaking this block. This checks tool type and
	 * harvest level requirement.
	 *
	 * In most cases this is also used to determine whether block drops should be created or not, except in some
	 * special cases such as vines.
	 *
	 * @param Item $tool
	 *
	 * @return bool
	 */
	public function isCompatibleWithTool(Item $tool) : bool{
		if($this->getHardness() < 0){
			return false;
		}

		$toolType = $this->getToolType();
		$harvestLevel = $this->getToolHarvestLevel();
		return $toolType === BlockToolType::TYPE_NONE or $harvestLevel === 0 or (
			($toolType & $tool->getBlockToolType()) !== 0 and $tool->getBlockToolHarvestLevel() >= $harvestLevel);
	}

	/**
	 * Do the actions needed so the block is broken with the Item
	 *
	 * @param Item        $item
	 * @param Player|null $player
	 *
	 * @return bool
	 */
	public function onBreak(Item $item, Player $player = null) : bool{
		return $this->getLevel()->setBlock($this, BlockFactory::get(Block::AIR));
	}


	/**
	 * Returns the seconds that this block takes to be broken using an specific Item
	 *
	 * @param Item $item
	 *
	 * @return float
	 */
	public function getBreakTime(Item $item) : float{
		$base = $this->getHardness();
		if($this->isCompatibleWithTool($item)){
			$base *= 1.5;
		}else{
			$base *= 5;
		}

		$efficiency = $item->getMiningEfficiency($this);
		if($efficiency <= 0){
			throw new \RuntimeException("Item efficiency is invalid");
		}

		$base /= $efficiency;

		return $base;
	}

	/**
	 * Called when this block or a block immediately adjacent to it changes state.
	 */
	public function onNearbyBlockChange() : void{

	}

	/**
	 * Returns whether random block updates will be done on this block.
	 *
	 * @return bool
	 */
	public function ticksRandomly() : bool{
		return false;
	}

	/**
	 * Called when this block is randomly updated due to chunk ticking.
	 * WARNING: This will not be called if ticksRandomly() does not return true!
	 */
	public function onRandomTick() : void{

	}

	/**
	 * Called when this block is updated by the delayed blockupdate scheduler in the level.
	 */
	public function onScheduledUpdate() : void{

	}

	/**
	 * Do actions when activated by Item. Returns if it has done anything
	 *
	 * @param Item        $item
	 * @param Player|null $player
	 *
	 * @return bool
	 */
	public function onActivate(Item $item, Player $player = null) : bool{
		return false;
	}

	/**
	 * Returns a base value used to compute block break times.
	 * @return float
	 */
	public function getHardness() : float{
		return 10;
	}

	/**
	 * Returns the block's resistance to explosions. Usually 5x hardness.
	 * @return float
	 */
	public function getBlastResistance() : float{
		return $this->getHardness() * 5;
	}

	/**
	 * @return float
	 */
	public function getFrictionFactor() : float{
		return 0.6;
	}

	/**
	 * @return int 0-15
	 */
	public function getLightLevel() : int{
		return 0;
	}

	/**
	 * Returns the amount of light this block will filter out when light passes through this block.
	 * This value is used in light spread calculation.
	 *
	 * @return int 0-15
	 */
	public function getLightFilter() : int{
		return 15;
	}

	/**
	 * Returns whether this block will diffuse sky light passing through it vertically.
	 * Diffusion means that full-strength sky light passing through this block will not be reduced, but will start being filtered below the block.
	 * Examples of this behaviour include leaves and cobwebs.
	 *
	 * Light-diffusing blocks are included by the heightmap.
	 *
	 * @return bool
	 */
	public function diffusesSkyLight() : bool{
		return false;
	}

	/**
	 * @return bool
	 */
	public function isTransparent() : bool{
		return false;
	}

	public function isSolid() : bool{
		return true;
	}

	/**
	 * AKA: Block->isFlowable
	 * @return bool
	 */
	public function canBeFlowedInto() : bool{
		return false;
	}

	public function hasEntityCollision() : bool{
		return false;
	}

	/**
	 * Returns whether entities can climb up this block.
	 * @return bool
	 */
	public function canClimb() : bool{
		return false;
	}

	public function isPassable() : bool{
		return !$this->isSolid();
	}

	public function addVelocityToEntity(Entity $entity, Vector3 $vector) : void{

	}

	/**
	 * @internal
	 *
	 * @param Level $level
	 * @param int   $x
	 * @param int   $y
	 * @param int   $z
	 */
	final public function position(Level $level, int $x, int $y, int $z) : void{
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->level = $level;
	}

	/**
	 * Returns an array of Item objects to be dropped
	 *
	 * @param Item $item
	 *
	 * @return Item[]
	 */
	public function getDrops(Item $item) : array{
		if($this->isCompatibleWithTool($item)){
			if($this->isAffectedBySilkTouch() and $item->hasEnchantment(Enchantment::SILK_TOUCH)){
				return $this->getSilkTouchDrops($item);
			}

			return $this->getDropsForCompatibleTool($item);
		}

		return [];
	}

	/**
	 * Returns an array of Items to be dropped when the block is broken using the correct tool type.
	 *
	 * @param Item $item
	 *
	 * @return Item[]
	 */
	public function getDropsForCompatibleTool(Item $item) : array{
		return [$this->getItem()];
	}

	/**
	 * Returns an array of Items to be dropped when the block is broken using a compatible Silk Touch-enchanted tool.
	 *
	 * @param Item $item
	 *
	 * @return Item[]
	 */
	public function getSilkTouchDrops(Item $item) : array{
		return [$this->getItem()];
	}

	/**
	 * Returns how much XP will be dropped by breaking this block with the given item.
	 *
	 * @param Item $item
	 *
	 * @return int
	 */
	public function getXpDropForTool(Item $item) : int{
		if($item->hasEnchantment(Enchantment::SILK_TOUCH) or !$this->isCompatibleWithTool($item)){
			return 0;
		}

		return $this->getXpDropAmount();
	}

	/**
	 * Returns how much XP this block will drop when broken with an appropriate tool.
	 *
	 * @return int
	 */
	protected function getXpDropAmount() : int{
		return 0;
	}

	/**
	 * Returns whether Silk Touch enchanted tools will cause this block to drop as itself. Since most blocks drop
	 * themselves anyway, this is implicitly true.
	 *
	 * @return bool
	 */
	public function isAffectedBySilkTouch() : bool{
		return true;
	}

	/**
	 * Returns the item that players will equip when middle-clicking on this block.
	 * @return Item
	 */
	public function getPickedItem() : Item{
		return $this->getItem();
	}

	/**
	 * Returns the time in ticks which the block will fuel a furnace for.
	 * @return int
	 */
	public function getFuelTime() : int{
		return 0;
	}

	/**
	 * Returns the chance that the block will catch fire from nearby fire sources. Higher values lead to faster catching
	 * fire.
	 *
	 * @return int
	 */
	public function getFlameEncouragement() : int{
		return 0;
	}

	/**
	 * Returns the base flammability of this block. Higher values lead to the block burning away more quickly.
	 *
	 * @return int
	 */
	public function getFlammability() : int{
		return 0;
	}

	/**
	 * Returns whether fire lit on this block will burn indefinitely.
	 *
	 * @return bool
	 */
	public function burnsForever() : bool{
		return false;
	}

	/**
	 * Returns whether this block can catch fire.
	 *
	 * @return bool
	 */
	public function isFlammable() : bool{
		return $this->getFlammability() > 0;
	}

	/**
	 * Called when this block is burned away by being on fire.
	 */
	public function onIncinerate() : void{

	}

	/**
	 * Returns the Block on the side $side, works like Vector3::getSide()
	 *
	 * @param int $side
	 * @param int $step
	 *
	 * @return Block
	 */
	public function getSide(int $side, int $step = 1){
		if($this->isValid()){
			return $this->getLevel()->getBlock(Vector3::getSide($side, $step));
		}

		return BlockFactory::get(Block::AIR, 0, Position::fromObject(Vector3::getSide($side, $step)));
	}

	/**
	 * Returns the 4 blocks on the horizontal axes around the block (north, south, east, west)
	 *
	 * @return Block[]
	 */
	public function getHorizontalSides() : array{
		return [
			$this->getSide(Facing::NORTH),
			$this->getSide(Facing::SOUTH),
			$this->getSide(Facing::WEST),
			$this->getSide(Facing::EAST)
		];
	}

	/**
	 * Returns the six blocks around this block.
	 *
	 * @return Block[]
	 */
	public function getAllSides() : array{
		return array_merge(
			[
				$this->getSide(Facing::DOWN),
				$this->getSide(Facing::UP)
			],
			$this->getHorizontalSides()
		);
	}

	/**
	 * Returns a list of blocks that this block is part of. In most cases, only contains the block itself, but in cases
	 * such as double plants, beds and doors, will contain both halves.
	 *
	 * @return Block[]
	 */
	public function getAffectedBlocks() : array{
		return [$this];
	}

	/**
	 * @return string
	 */
	public function __toString(){
		return "Block[" . $this->getName() . "] (" . $this->getId() . ":" . $this->getDamage() . ")";
	}

	/**
	 * Checks for collision against an AxisAlignedBB
	 *
	 * @param AxisAlignedBB $bb
	 *
	 * @return bool
	 */
	public function collidesWithBB(AxisAlignedBB $bb) : bool{
		foreach($this->getCollisionBoxes() as $bb2){
			if($bb->intersectsWith($bb2)){
				return true;
			}
		}

		return false;
	}

	/**
	 * @param Entity $entity
	 */
	public function onEntityCollide(Entity $entity) : void{

	}

	/**
	 * @param Entity $entity
	 * @param float  $fallDistance
	 */
	public function onEntityFallenUpon(Entity $entity, float $fallDistance) : void{

	}

	/**
	 * @param Entity $entity
	 */
	public function onEntityCollideUpon(Entity $entity) : void{

	}

	/**
	 * @return AxisAlignedBB[]
	 */
	public function getCollisionBoxes() : array{
		if($this->collisionBoxes === null){
			$this->collisionBoxes = $this->recalculateCollisionBoxes();
			foreach($this->collisionBoxes as $bb){
				$bb->offset($this->x, $this->y, $this->z);
			}
		}

		return $this->collisionBoxes;
	}

	/**
	 * @return AxisAlignedBB[]
	 */
	protected function recalculateCollisionBoxes() : array{
		if($bb = $this->recalculateBoundingBox()){
			return [$bb];
		}

		return [];
	}

	/**
	 * @return AxisAlignedBB|null
	 */
	public function getBoundingBox() : ?AxisAlignedBB{
		if($this->boundingBox === null){
			$this->boundingBox = $this->recalculateBoundingBox();
			if($this->boundingBox !== null){
				$this->boundingBox->offset($this->x, $this->y, $this->z);
			}
		}
		return $this->boundingBox;
	}

	/**
	 * @return AxisAlignedBB|null
	 */
	protected function recalculateBoundingBox() : ?AxisAlignedBB{
		return AxisAlignedBB::one();
	}

	/**
	 * @param Vector3 $pos1
	 * @param Vector3 $pos2
	 *
	 * @return RayTraceResult|null
	 */
	public function calculateIntercept(Vector3 $pos1, Vector3 $pos2) : ?RayTraceResult{
		$bbs = $this->getCollisionBoxes();
		if(empty($bbs)){
			return null;
		}

		/** @var RayTraceResult|null $currentHit */
		$currentHit = null;
		/** @var int|float $currentDistance */
		$currentDistance = PHP_INT_MAX;

		foreach($bbs as $bb){
			$nextHit = $bb->calculateIntercept($pos1, $pos2);
			if($nextHit === null){
				continue;
			}

			$nextDistance = $nextHit->hitVector->distanceSquared($pos1);
			if($nextDistance < $currentDistance){
				$currentHit = $nextHit;
				$currentDistance = $nextDistance;
			}
		}

		return $currentHit;
	}

	public function setMetadata(string $metadataKey, MetadataValue $newMetadataValue){
		if($this->isValid()){
			$this->level->getBlockMetadata()->setMetadata($this, $metadataKey, $newMetadataValue);
		}
	}

	public function getMetadata(string $metadataKey){
		if($this->isValid()){
			return $this->level->getBlockMetadata()->getMetadata($this, $metadataKey);
		}

		return null;
	}

	public function hasMetadata(string $metadataKey) : bool{
		if($this->isValid()){
			return $this->level->getBlockMetadata()->hasMetadata($this, $metadataKey);
		}

		return false;
	}

	public function removeMetadata(string $metadataKey, Plugin $owningPlugin){
		if($this->isValid()){
			$this->level->getBlockMetadata()->removeMetadata($this, $metadataKey, $owningPlugin);
		}
	}
}