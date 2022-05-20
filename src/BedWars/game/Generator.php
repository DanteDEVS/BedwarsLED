<?php


namespace BedWars\game;

use BedWars\game\entity\FakeItemEntity;
use BedWars\utils\Utils;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\world\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class Generator extends Vector3
{

	const TITLE = [
		\pocketmine\item\ItemIds::DIAMOND => TextFormat::BOLD . TextFormat::AQUA . "Diamond",
		\pocketmine\item\ItemIds::EMERALD => TextFormat::BOLD . TextFormat::GREEN . "Emerald"
	];
	const FAKE_BLOCK = [
		\pocketmine\item\ItemIds::DIAMOND => \pocketmine\item\ItemIds::DIAMOND_BLOCK,
		\pocketmine\item\ItemIds::EMERALD => \pocketmine\item\ItemIds::EMERALD_BLOCK
	];
	/** @var int $itemID */
	public $itemID;
	/** @var int $repeatRate */
	private $repeatRate;
	/** @var Position $position */
	public $position;
	/** @var bool $spawnText */
	private $spawnText;
	/** @var bool $spawnBlock */
	private $spawnBlock;
	/** @var int $dynamicSpawnTime */
	private $dynamicSpawnTime;
	/** @var FloatingTextParticle $floatingText */
	private $floatingText;
	/** @var $blockEntity */
	private $blockEntity;
	/** @var int $tier */
	private $tier = 1;
	/** @var Team|null */
	private $team;
	/**
	 * @var bool
	 */
	public $activated;

	/**
	 * Generator constructor.
	 * @param int $itemID
	 * @param int $repeatRate
	 * @param Position $position
	 * @param bool $spawnText
	 * @param bool $spawnBlock
	 * @param bool $activated
	 */
	public function __construct(int $itemID, int $repeatRate, Position $position, bool $spawnText, bool $spawnBlock, bool $activated = true)
	{
		parent::__construct($position->x, $position->y, $position->z);
		$this->itemID = $itemID;
		$this->repeatRate = $repeatRate;
		$this->position = $position;
		$this->spawnText = $spawnText;
		$this->spawnBlock = $spawnBlock;

		$this->dynamicSpawnTime = $repeatRate;

		if ($this->spawnText) {
			$text = TextFormat::YELLOW . "Tier " . TextFormat::RED . Utils::rome($this->tier) . "\n" .
				self::TITLE[$itemID] . "\n\n" .
				TextFormat::YELLOW . "Spawns in " . TextFormat::RED . $this->dynamicSpawnTime . "seconds";
			$this->floatingText = new FloatingTextParticle($position->add(0,2.5,0), $text, "");
		}

		if ($this->spawnBlock) {
			$path = Server::getInstance()->getDataPath() . "plugin_data/BedWars/skins/" . $itemID . ".png";
			$skin = Utils::getSkinFromFile($path);
			$nbt = Entity::createBaseNBT($position->add(0,2,0), null);
			$nbt->setString("GeneratorEntity", "true");
			$nbt->setTag(new CompoundTag('Skin', [
				new StringTag('Data', $skin->getSkinData()),
				new StringTag('Name', 'Standard_CustomSlim'),
				new StringTag('GeometryName', 'geometry.player_head'),
				new ByteArrayTag('GeometryData', FakeItemEntity::GEOMETRY)]));
			$fakeItem = new FakeItemEntity($position->level, $nbt);
			$fakeItem->setScale(1.4);
			$fakeItem->spawnToAll();
		}
		$this->activated = $activated;
	}


	/**
	 * @param int $repeatRate
	 */
	public function setRepeatRate(int $repeatRate): void
	{
		$this->repeatRate = $repeatRate;
	}

	public function tick(): void
	{
		if ($this->spawnText) {
			$text = TextFormat::YELLOW . "Tier " . TextFormat::RED . Utils::rome($this->tier) . "\n" .
				self::TITLE[$this->itemID] . "\n" .
				TextFormat::YELLOW . "Spawn in " . TextFormat::RED . $this->dynamicSpawnTime;
			$this->floatingText->setText($text);
			foreach ($this->floatingText->encode() as $packet) {
				foreach ($this->position->getWorld()->getPlayers() as $player) {
					$player->getNetworkSession()->sendDataPacket($packet);
				}
			}
		}

		if ($this->dynamicSpawnTime == 0) {
			$this->dynamicSpawnTime = $this->repeatRate;
			if ($this->activated){
				$this->position->getWorld()->dropItem($this->position->getPosition()->asVector3(), \pocketmine\item\ItemFactory::getInstance()->get($this->itemID));
			}
		}

		$this->dynamicSpawnTime--;

	}

	public function getTier(): int
	{
		return $this->tier;
	}

	public function updateTier(): void
	{
		$this->tier++;

		$this->repeatRate = $this->repeatRate - 10;
	}

	/**
	 * @return FakeItemEntity
	 */
	public function getBlockEntity(): ?FakeItemEntity
	{
		return $this->blockEntity;
	}

	/**
	 * @return FloatingTextParticle|null
	 */
	public function getFloatingText(): ?FloatingTextParticle
	{
		return $this->floatingText;
	}


}