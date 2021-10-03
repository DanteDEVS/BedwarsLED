<?php /** @noinspection PhpPossiblePolymorphicInvocationInspection */


namespace BedWars\game;

use BedWars\{BedWars, utils\Scoreboard};
use BedWars\game\player\PlayerCache;
use BedWars\utils\Utils;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Compass;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\level\sound\{BlazeShootSound};
use pocketmine\level\sound\{EndermanTeleportSound};
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\{LevelSoundEventPacket};
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class Game
{

	public const STATE_LOBBY = 0;
	public const STATE_RUNNING = 1;
	public const STATE_REBOOT = 2;
	/** @var int $playersPerTeam */
	public $playersPerTeam;
	/** @var string $worldName */
	public $worldName;
	/** @var array $players */
	public $players = array();
	/** @var array $spectators */
	public $spectators = array();
	/** @var array $teamInfo */
	public $teamInfo = array();
	/** @var array $teams */
	public $teams = array();
	/** @var array $deadQueue */
	public $deadQueue = [];
	/** @var Entity[] $npcs */
	public $npcs = [];
	/** @var Generator[] $generators */
	public $generators = array();
	/** @var array $placedBlocks */
	public $placedBlocks = array();
	/** @var BedWars $plugin */
	private $plugin;
	/** @var string $gameName */
	private $gameName;
	/** @var int $minPlayers */
	private $minPlayers;
	/** @var int $maxPlayers */
	private $maxPlayers;
	/** @var string $lobbyName */
	private $lobbyName;
	/** @var string $mapName */
	private $mapName;
	/** @var int $state */
	private $state = self::STATE_LOBBY;
	/** @var bool $starting */
	private $starting = false;
	/** @var Vector3 $lobby */
	private $lobby;
	/** @var int $startTime */
	private $startTime;
	/** @var int $rebootTime */
	private $rebootTime;
	/** @var int $voidY */
	private $voidY;
	/** @var string $winnerTeam */
	private $winnerTeam = '';
	/** @var array $trackingPositions */
	private $trackingPositions = [];
	/** @var array $generatorInfo */
	private $generatorInfo;
	/** @var float|int $tierUpdate */
	private $tierUpdate = 20 * 1;
	/** @var string $tierUpdateGen */
	private $tierUpdateGen = "diamond";
	/** @var PlayerCache[] $cachedPlayers */
	private $cachedPlayers = array();

	/**
	 * Game constructor.
	 * @param BedWars $plugin
	 * @param array $data
	 */
	public function __construct(BedWars $plugin, array $data)
	{
		$this->plugin = $plugin;
		$this->startTime = $plugin->staticStartTime;
		$this->rebootTime = $plugin->staticRestartTime;
		$this->gameName = $data['name'];
		$this->minPlayers = $data['minPlayers'];
		$this->playersPerTeam = $data['playersPerTeam'];
		$this->worldName = $data['world'];
		$lobbyVector = explode(":", $data['lobby']);
		$this->lobby = new Position((float)$lobbyVector[0], (float)$lobbyVector[1], (float)$lobbyVector[2], Server::getInstance()->getLevelByName($data['mapName']));
		$this->lobbyName = explode(":", $data['lobby'][3]);
		$this->mapName = $data['mapName'];
		$this->teamInfo = $data['teamInfo'];
		$this->generatorInfo = $data['generatorInfo'][$this->gameName] ?? [];

		foreach ($this->teamInfo as $teamName => $parsedData) {
			$this->teams[$teamName] = new Team($teamName, BedWars::TEAMS[strtolower($teamName)]);
		}
		
		$this->maxPlayers = count($this->teams) * $this->playersPerTeam;
		$this->reload();
		$this->plugin->getScheduler()->scheduleRepeatingTask(new GameTick($this), 20);
	}

	public function reload(): void
	{
		$this->plugin->getServer()->loadLevel($this->worldName);
		$world = $this->plugin->getServer()->getLevelByName($this->worldName);
		if (!$world instanceof Level) {
			$this->plugin->getLogger()->info(BedWars::PREFIX . TextFormat::YELLOW . "Failed to load arena " . $this->gameName . " because it's world does not exist!");
			return;
		}
		$world->setAutoSave(false);
	}

	/**
	 * @param int $limit
	 */
	public function setVoidLimit(int $limit): void
	{
		$this->voidY = $limit;
	}

	/**
	 * @param Vector3 $lobby
	 */
	public function setLobby(Vector3 $lobby): void
	{
		$this->lobby = new Position($lobby->x, $lobby->y, $lobby->z);
	}

	/**
	 * @return int
	 */
	public function getVoidLimit(): int
	{
		return $this->voidY;
	}

	/**
	 * @return int
	 */
	public function getState(): int
	{
		return $this->state;
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->gameName;
	}

	/**
	 * @return string
	 */
	public function getMapName(): string
	{
		return $this->mapName;
	}

	/**
	 * @return int
	 */
	public function getMaxPlayers(): int
	{
		return $this->maxPlayers;
	}

	/**
	 * @param Player $player
	 */
	public function join(Player $player): void
	{
		if ($this->state !== self::STATE_LOBBY) {
			$player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena is full!");
			return;
		}

		$this->cachedPlayers[$player->getRawUniqueId()] = new PlayerCache($player);
		$player->teleport(Server::getInstance()->getLevelByName($this->mapName)->getSafeSpawn());
		$player->teleport($this->lobby->asVector3(), $player->yaw, $player->pitch);
		$this->players[$player->getRawUniqueId()] = $player;
		
		$this->broadcastMessage(TextFormat::GRAY . $player->getName() . " " . TextFormat::YELLOW . "has joined the game " . TextFormat::YELLOW . "(" . TextFormat::AQUA . count($this->players) . TextFormat::YELLOW . "/" . TextFormat::AQUA . $this->maxPlayers . TextFormat::YELLOW . ")");
		$player->getLevel()->broadcastLevelSoundEvent($player, LevelSoundEventPacket::SOUND_CONDUIT_ACTIVATE);
		$player->getInventory()->clearAll();
		$player->getArmorInventory()->clearAll();
		$player->setGamemode(2);
		$player->setFood(20);
		$player->removeAllEffects();
		$player->setAllowFlight(false);
		$player->setHealth(20);
		$player->setFood(20);

		$a = 0;
		$items = array_fill(0, count($this->teams), Item::get(Item::WOOL));
		foreach ($this->teams as $team) {
			$items[$a]->setDamage(Utils::colorIntoWool($team->getColor()));
			$player->getInventory()->addItem($items[$a]);
			$a++;
		}

		$player->getInventory()->setItem(8, Item::get(Item::COMPASS)->setCustomName(TextFormat::YELLOW . "Leave"));
		$this->checkLobby();
	}

	/**
	 * @param string $message
	 */
	public function broadcastMessage(string $message): void
	{
		foreach (array_merge($this->spectators, $this->players) as $player) {
			$player->sendMessage($message);
		}
	}

	private function checkLobby(): void
	{
		if (!$this->starting && count($this->players) >= $this->minPlayers) {
			$this->starting = true;
			$this->broadcastMessage(TextFormat::GREEN . "§lCountdown started");
		}
	}

	/**
	 * @param Player $player
	 */
	public function trackCompass(Player $player): void
	{
		$currentTeam = $this->trackingPositions[$player->getRawUniqueId()];
		$arrayTeam = $this->teams;
		$position = array_search($currentTeam, array_keys($arrayTeam), true);
		$teams = array_values($this->teams);
		$team = null;

		if (isset($teams[$position + 1])) {
			$team = $teams[$position + 1]->getName();
		} else {
			$team = $teams[0]->getName();
		}

		$this->trackingPositions[$player->getRawUniqueId()] = $team;

		$player->setSpawn(Utils::stringToVector(":", $spawnPos = $this->teamInfo[$team]['spawnPos']));
		$player->setSpawn(Utils::stringToVector(":", $spawnPos = $this->teamInfo[$team]['spawnPos']));

		foreach ($player->getInventory()->getContents() as $slot => $item) {
			if ($item instanceof Compass) {
				$player->getInventory()->removeItem($item);
				$player->getInventory()->setItem($slot, Item::get(Item::COMPASS)->setCustomName(TextFormat::GREEN . "Tap to switch"));
				$player->getLevel()->addSound(new BlazeShootSound($player));
			}
		}
	}

	/**
	 * @param Team $team
	 * @param Player $player
	 */
	public function breakBed(Team $team, Player $player): void
	{
		$team->updateBedState(false);

		$playerTeam = $this->plugin->getPlayerTeam($player);
		if ($playerTeam !== null) {
			$this->broadcastMessage($team->getColor() . $team->getName() . "'s '" . TextFormat::GRAY . "bed was destroyed by " . $playerTeam->getColor() . $player->getName());
			$this->plugin->addEliminationb($player);
			foreach ($team->getPlayers() as $parsedPlayer) {
				$parsedPlayer->sendTitle(TextFormat::RED . "§lBed Destroyed!", TextFormat::GRAY . "You will no longer respawn");

				self::playSound($parsedPlayer, 'mob.enderdragon.death', 1, 1);
			}
		}
	}

	public static function playSound(Player $player, string $soundName, float $volume = 0, float $pitch = 0): void
	{
		$pk = new PlaySoundPacket();
		$pk->soundName = $soundName;
		$pk->x = (int)$player->x;
		$pk->y = (int)$player->y;
		$pk->z = (int)$player->z;
		$pk->volume = $volume;
		$pk->pitch = $pitch;
		$player->dataPacket($pk);
	}

	/**
	 * @param Player $player
	 */
	public function quit(Player $player): void
	{
		if (isset($this->players[$player->getRawUniqueId()])) {
			$team = $this->plugin->getPlayerTeam($player);
			if ($team instanceof Team) {
				$team->remove($player);
			}
			unset($this->players[$player->getRawUniqueId()]);
		}
		if (isset($this->spectators[$player->getRawUniqueId()])) {
			unset($this->spectators[$player->getRawUniqueId()]);
		}


	}

	/**
	 * @param Player $player
	 * @noinspection PhpParamsInspection
	 */
	public function killPlayer(Player $player): void
	{
		$playerTeam = $this->plugin->getPlayerTeam($player);
		if ($player->isSpectator()) {
			return;
		}

		if ($playerTeam !== null && !$playerTeam->hasBed()) {
			$playerTeam->dead++;
			$this->spectators[$player->getRawUniqueId()] = $player;
			unset($this->players[$player->getRawUniqueId()]);
			$player->setGamemode(Player::SPECTATOR);
			$player->sendTitle(TextFormat::BOLD . TextFormat::RED . "§lYOU DIED!", TextFormat::GRAY . "You will no longer respawn");
			$player->getInventory()->setItem(5, Item::get(Item::COMPASS)->setCustomName(TextFormat::YELLOW . "Leave"));
			$player->getLevel()->addSound(new EndermanTeleportSound($player));
		} else {
			$player->setGamemode(Player::SPECTATOR);
			$player->getInventory()->clearAll();
			$player->getArmorInventory()->clearAll();
			$this->deadQueue[$player->getRawUniqueId()] = 5;
		}

		$cause = $player->getLastDamageCause();
		if ($cause === null) {
			return;
		} //probadly handled the event itself
		$playerTeam = $this->plugin->getPlayerTeam($player);
		switch ($cause->getCause()) {
			case EntityDamageEvent::CAUSE_ENTITY_ATTACK;
				$damager = $cause->getDamager();
				if ($playerTeam !== null) {
					$this->broadcastMessage($playerTeam->getColor() . $player->getName() . " " . TextFormat::GRAY . "was killed by " . $this->plugin->getPlayerTeam($damager)->getColor() . $damager->getName());
					$this->plugin->addElimination($damager);
					$kills = BedWars::getConfigs('kills');
					$kills->set($damager->getName(), $kills->get($damager->getName()) + 1);
					$kills->save();
					$player->getInventory()->clearAll();
					$player->getArmorInventory()->clearAll();
				}
				break;
			case EntityDamageEvent::CAUSE_PROJECTILE;
				if ($cause instanceof EntityDamageByChildEntityEvent) {
					$damager = $cause->getDamager();
					if ($playerTeam !== null) {
                                                $this->broadcastMessage($playerTeam->getColor() . $player->getName() . " " . TextFormat::GRAY . "was shot by " . $this->plugin->getPlayerTeam($damager)->getColor() . $damager->getName());
						$this->plugin->addElimination($damager);
						$kills = BedWars::getConfigs('kills');
						$kills->set($damager->getName(), $kills->get($damager->getName()) + 1);
						$kills->save();
						$player->getInventory()->clearAll();
						$player->getArmorInventory()->clearAll();
					}
				}
				break;
			case EntityDamageEvent::CAUSE_FIRE;
				if ($playerTeam !== null) {
					$this->broadcastMessage($playerTeam->getColor() . $player->getName() . " " . TextFormat::GRAY . "went up in flame");
					$player->getInventory()->clearAll();
					$player->getArmorInventory()->clearAll();
				}
				break;
			case EntityDamageEvent::CAUSE_VOID;
				$player->teleport($player->add(0, $this->voidY + 5, 0));
				$player->getInventory()->clearAll();
				$player->getArmorInventory()->clearAll();
				break;
		}

	}

	public function tick(): void
	{

		switch ($this->state) {
			case self::STATE_LOBBY;
				if ($this->starting) {
					if ($this->starting && count($this->players) < $this->minPlayers) {
						$this->starting = false;
						$this->broadcastMessage(TextFormat::YELLOW . "§lCountdown stopped");
					}

					$this->startTime--;

					foreach ($this->players as $player) {
						$player->sendTip(TextFormat::YELLOW . "§lBedWars§r §bStarting in " . TextFormat::GRAY . gmdate("i:s", $this->startTime));
						self::playSound($player, 'note.cow_bell', 1, 1);
					}

					switch ($this->startTime) {
						case 30;
							$this->broadcastMessage(TextFormat::YELLOW . "Game Starting in §6" . "30");
							break;
						case 15;
							$this->broadcastMessage(TextFormat::YELLOW . "Game Starting in §c" . "15");
							break;
						case 10;
						case 5;
						case 4;
						case 3;
						case 2;
						case 1;
							foreach ($this->players as $player) {
								$player->sendTitle(TextFormat::RED . $this->startTime, "§bGet ready to ");
								$player->getLevel()->broadcastLevelSoundEvent($player, LevelSoundEventPacket::SOUND_BOTTLE_DRAGONBREATH);
							}
							break;
					}

					if ($this->startTime === 0) {
						$this->start();
					}
				} else {
					foreach ($this->players as $player) {
						$player->sendTip(TextFormat::YELLOW . "§lBEDWARS\n§r§aWaiting for players §e(" . TextFormat::AQUA . ($this->minPlayers - count($this->players)) . TextFormat::YELLOW . ")");
					}
				}

				foreach (array_merge($this->players, $this->spectators) as $player) {
					Scoreboard::new($player, 'bedwars', TextFormat::BOLD . TextFormat::YELLOW . "BEDWARS");
					Scoreboard::setLine($player, 1, " ");
					Scoreboard::setLine($player, 2, " " . TextFormat::WHITE . "Map: " . TextFormat::GREEN . $this->mapName . str_repeat(" ", 3));
					Scoreboard::setLine($player, 3, " " . TextFormat::WHITE . "Players: " . TextFormat::GREEN . count($this->players) . "/" . $this->maxPlayers . str_repeat(" ", 3));
					Scoreboard::setLine($player, 4, "  ");
					Scoreboard::setLine($player, 5, " " . ($this->starting ? TextFormat::WHITE . "Starting in " . TextFormat::GREEN . $this->startTime . str_repeat(" ", 3) : TextFormat::GREEN . "Waiting for players..." . str_repeat(" ", 3)));
					Scoreboard::setLine($player, 6, "   ");
					Scoreboard::setLine($player, 7, " " . TextFormat::WHITE . "Mode: " . TextFormat::GREEN . substr(str_repeat($this->playersPerTeam . "v", count($this->teams)), 0, -1) . str_repeat(" ", 3));
					Scoreboard::setLine($player, 8, "    ");
					Scoreboard::setLine($player, 9, " " . TextFormat::YELLOW . "§eurservername.net");
				}

				break;
			case self::STATE_RUNNING;

				foreach ($this->players as $player) {
					/** @var Player $player */

					if ($player->getInventory()->contains(Item::get(Item::COMPASS))) {
						$trackIndex = $this->trackingPositions[$player->getRawUniqueId()];
						$team = $this->teams[$trackIndex];
						$player->sendTip(TextFormat::WHITE . "Tracking: " . TextFormat::BOLD . $team->getColor() . ucfirst($team->getName()) . " " . TextFormat::RESET . TextFormat::WHITE . "- Distance: " . TextFormat::BOLD . $team->getColor() . round(Utils::stringToVector(":", $this->teamInfo[$trackIndex]['spawnPos'])->distance($player)) . "m");
					}

					if (isset($this->deadQueue[$player->getRawUniqueId()])) {

						$player->sendTitle(TextFormat::RED . "§lYOU DIED!", TextFormat::YELLOW . "You will respawn in " . TextFormat::RED . $this->deadQueue[$player->getRawUniqueId()] . " " . TextFormat::YELLOW . "seconds!");
						$player->sendMessage(TextFormat::YELLOW . "You will respawn in " . TextFormat::RED . $this->deadQueue[$player->getRawUniqueId()] . " " . TextFormat::YELLOW . "seconds!");
						$player->getLevel()->broadcastLevelSoundEvent($player, LevelSoundEventPacket::SOUND_NOTE, $this->deadQueue[$player->getRawUniqueId()]);

						--$this->deadQueue[$player->getRawUniqueId()];
						if ($this->deadQueue[$player->getRawUniqueId()] === 0) {
							unset($this->deadQueue[$player->getRawUniqueId()]);

							$this->respawnPlayer($player);
							$player->sendTitle(TextFormat::GREEN . "§lRESPAWNED!");
							$player->sendMessage(TextFormat::YELLOW . "You have respawned!");
						}
					}
				}

				foreach (array_merge($this->players, $this->spectators) as $player) {
					Scoreboard::remove($player);
					Scoreboard::new($player, 'bedwars', TextFormat::BOLD . TextFormat::YELLOW . "BEDWARS");
					Scoreboard::setLine($player, 1, "§7 " . date("d/m/Y"));

					Scoreboard::setLine($player, 2, " ");
					Scoreboard::setLine($player, 3, " " . TextFormat::WHITE . ucfirst($this->tierUpdateGen) . " Upgrade:     ");

					Scoreboard::setLine($player, 4, "§a " . gmdate("i:s", $this->tierUpdate));
					Scoreboard::setLine($player, 5, "  ");

					$currentLine = 6;
					$playerTeam = $this->plugin->getPlayerTeam($player);
					foreach ($this->teams as $team) {
						/** @var Team $team */
						if (!$team->hasBed() && (count($team->getPlayers()) - $team->dead) === 0) {
							foreach ($this->npcs as $entityId => $data) {
								if ($data[0] === $team->getName()) {
									foreach ($player->getLevel()->getEntities() as $entity) {
										if ($entity->getId() === $entityId) {
											if ($entity !== null) {
												foreach ($entity->getLevel()->getNearbyEntities($entity->getBoundingBox()->expandedCopy(20, 10, 20), $entity) as $generator) {
													if ($generator instanceof Player) {
														continue;
													}
													foreach ($this->generators as $generatorEntity) {
														if ($generator->distance($generatorEntity) < 10) {
															unset($this->generators[array_search($generatorEntity, $this->generators)]);
														}
													}
													$entity->close();
												}
											}
											$entity->close();
										}
									}
								}
							}
						}
						if ($team->hasBed()) {
							$status = TextFormat::GREEN . "⩋";
						} elseif (!$team->hasBed() && $team->getPlayerCount() !== 0) {
							$status = TextFormat::GREEN . "" . $team->getPlayerCount() . "";
						} elseif (!$team->hasBed() && $team->getPlayerCount() <= 0) {
							$status = TextFormat::RED . "⩕";
						} else {
							$status = TextFormat::RED . "⩕";
						}
						$isPlayerTeam = $team->getName() === $playerTeam->getName() ? TextFormat::GRAY . "(YOU)" : "";
						$stringFormat = " " . TextFormat::BOLD . $team->getColor() . ucfirst($team->getName()[0]) . "§l " . TextFormat::RESET . TextFormat::WHITE . ucfirst($team->getName()) . ": " . $status . " " . $isPlayerTeam;
						Scoreboard::setLine($player, " " . $currentLine, $stringFormat);
						$currentLine++;
					}
					Scoreboard::setLine($player, " " . $currentLine++, "       ");

					Scoreboard::setLine($player, " " . $currentLine++, " §fKills: §a" . $this->plugin->getEliminations($player));
					Scoreboard::setLine($player, " " . $currentLine++, " §fFinal Kills: §a");
					Scoreboard::setLine($player, " " . $currentLine++, " §fBeds Broken: §a" . $this->plugin->getEliminationsb($player));
					Scoreboard::setLine($player, " " . $currentLine++, "§f ");
					Scoreboard::setLine($player, " " . $currentLine++, " §eurservername.net");
				}

				if (count($team = $this->getAliveTeams()) === 1 && count($this->players) === count($this->teams[$team[0]]->getPlayers())) {
					$this->winnerTeam = $team[0];


					$this->state = self::STATE_REBOOT;
				}

				foreach ($this->generators as $generator) {
					$generator->tick();
				}

				$this->tierUpdate--;

				if ($this->tierUpdate == 0) {
					$this->tierUpdate = 20 * 1;
					foreach ($this->generators as $generator) {
						if ($generator->itemID == Item::DIAMOND && $this->tierUpdateGen == "diamond") {
							$generator->updateTier();
						} elseif ($generator->itemID == Item::EMERALD && $this->tierUpdateGen == "emerald") {
							$generator->updateTier();
						}
					}
					$this->tierUpdateGen = $this->tierUpdateGen == 'diamond' ? 'emerald' : 'diamond';
				}
				break;
			case Game::STATE_REBOOT;
				$team = $this->teams[$this->winnerTeam];
				if ($this->rebootTime == 10) {
					foreach ($team->getPlayers() as $player) {
						$player->sendTitle(TextFormat::BOLD . TextFormat::GOLD . "VICTORY!", TextFormat::GRAY . "You were the last one standing");
						$Wins = BedWars::getConfigs('wins');
						$Wins->set($player->getName(), $Wins->get($player->getName()) + 1);
						$Wins->save();
					}
				}

				--$this->rebootTime;
				if ($this->rebootTime == 0) {
					$this->stop();
				}
				break;
		}
	}

	public function start(): void
	{
		foreach ($this->players as $player) {
			$player->sendTitle("§a§lGAME STARTED!");
			$player->getLevel()->broadcastLevelSoundEvent($player, LevelSoundEventPacket::SOUND_BLOCK_BELL_HIT);
			$player->setGamemode(0);
		}
		$this->broadcastMessage(TextFormat::GREEN . "Game has started! ");
		$this->state = self::STATE_RUNNING;

		foreach ($this->players as $player) {
			$playerTeam = $this->plugin->getPlayerTeam($player);

			if ($playerTeam === null) {
				$players = array();
				foreach ($this->teams as $name => $object) {
					$players[$name] = count($object->getPlayers());
				}

				$lowest = min($players);
				$teamName = array_search($lowest, $players, true);

				$team = $this->teams[$teamName];
				$team->add($player);
				$playerTeam = $team;
			}

			$playerTeam->setArmor($player, 'leather');

			$this->respawnPlayer($player);
			$player->setNameTag(TextFormat::BOLD . $playerTeam->getColor() . strtoupper($playerTeam->getName()[0]) . " " . TextFormat::RESET . $playerTeam->getColor() . $player->getName());

			$this->trackingPositions[$player->getRawUniqueId()] = $playerTeam->getName();
			$player->setSpawn(Utils::stringToVector(":", $spawnPos = $this->teamInfo[$playerTeam->getName()]['spawnPos']));
		}

		$this->plugin->getScheduler()->scheduleDelayedTask(new class($this) extends Task {

			private $game;

			public function __construct(Game $game)
			{
				$this->game = $game;
			}

			/**
			 * @inheritDoc
			 */
			public function onRun(int $currentTick): void
			{
				$this->game->initShops();
				$this->game->initGenerators();
			}
		}, 40);
		$this->initTeams(); //bedwars join bedwars
	}

	/**
	 * @param Player $player
	 */
	public function respawnPlayer(Player $player): void
	{
		$team = $this->plugin->getPlayerTeam($player);
		if ($team === null) return;

		$spawnPos = $this->teamInfo[$team->getName()]['spawnPos'];

		$player->setGamemode(Player::SURVIVAL);
		$player->setFood($player->getMaxFood());
		$player->setHealth($player->getMaxHealth());
		$player->getArmorInventory()->clearAll(true);
		$player->getInventory()->clearAll();
		$player->teleport($this->plugin->getServer()->getLevelByName($this->worldName)->getSafeSpawn());
		$player->getInventory()->clearAll();
		$player->teleport(Utils::stringToVector(":", $spawnPos));

		//inventory
		$helmet = Item::get(Item::LEATHER_CAP);
		$chestplate = Item::get(Item::LEATHER_CHESTPLATE);
		$leggings = Item::get(Item::LEATHER_LEGGINGS);
		$boots = Item::get(Item::LEATHER_BOOTS);

		$hasArmorUpdated = true;

		switch ($team->getArmor($player)) {
			case "iron";
				$leggings = Item::get(Item::IRON_LEGGINGS);
				break;
			case "diamond";
				$boots = Item::get(Item::IRON_BOOTS);
				break;
			default;
				$hasArmorUpdated = false;
				break;
		}


		foreach (array_merge([$helmet, $chestplate], !$hasArmorUpdated ? [$leggings, $boots] : []) as $armor) {
			$armor->setCustomColor(Utils::colorIntoObject($team->getColor()));
		}

		$armorUpgrade = $team->getUpgrade('armorProtection');
		if ($armorUpgrade > 0) {
			foreach ([$helmet, $chestplate, $leggings, $boots] as $armor) {
				$armor->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment(Enchantment::PROTECTION)), $armorUpgrade);
			}
		}

		$player->getArmorInventory()->setHelmet($helmet);
		$player->getArmorInventory()->setChestplate($chestplate);
		$player->getArmorInventory()->setLeggings($leggings);
		$player->getArmorInventory()->setBoots($boots);

		$sword = Item::get(Item::WOODEN_SWORD);

		$swordUpgrade = $team->getUpgrade('sharpenedSwords');
		if ($swordUpgrade > 0) {
			$sword->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment(Enchantment::SHARPNESS), $swordUpgrade));
		}

		$player->getInventory()->setItem(0, $sword);
		$player->getInventory()->setItem(8, Item::get(Item::COMPASS)->setCustomName(TextFormat::GREEN . "Tap to switch"));

	}

	public function initShops(): void
	{
                    foreach ($this->teamInfo as $team => $info) {
			$shopPos = Utils::stringToVector(":", $info['shopPos']);
			$level = $this->plugin->getServer()->getLevelByName($this->worldName);
			if ($level !== null) {
				$chunk = $level->getChunk($shopPos->getFloorX() >> 4, $shopPos->getFloorZ() >> 4, true);
				$level->loadChunk($chunk->getX(), $chunk->getZ());
				$nbt = Entity::createBaseNBT($shopPos, null);
				$nbt->setTag(new StringTag("Team", $team));
				$nbt->setString("GameEntity", "shop");
				$entity = Entity::createEntity("Villager", $this->plugin->getServer()->getLevelByName($this->worldName), $nbt);
				if ($entity !== null) {
					$entity->setNameTag(TextFormat::AQUA . "ITEM SHOP\n" . TextFormat::BOLD . TextFormat::YELLOW . "TAP TO USE");
					$entity->setNameTagAlwaysVisible(true);
					$entity->spawnToAll();
					$this->npcs[$entity->getId()] = [$team, 'shop'];
				}
			}

			$upgradePos = Utils::stringToVector(":", $info['upgradePos']);
			$rotation = explode(":", $info['upgradePos']);

			$nbt = Entity::createBaseNBT($upgradePos, null);
			$nbt->setTag(new StringTag("Team", $team));
			$nbt->setString("GameEntity", "upgrade");
			$entity = Entity::createEntity("Villager", $this->plugin->getServer()->getLevelByName($this->worldName), $nbt);
			$entity->setNameTag(TextFormat::AQUA . "TEAM UPGRADES\n" . TextFormat::BOLD . TextFormat::YELLOW . "TAP TO USE");
			$entity->setNameTagAlwaysVisible(true);
			$entity->spawnToAll();

			$this->npcs[$entity->getId()] = [$team, 'upgrade'];
		}
	}
	
	public function initGenerators(): void
	{
		foreach ($this->generatorInfo as $generator) {
			$generatorData = BedWars::GENERATOR_PRIORITIES[$generator['type']];
			$item = $generatorData['item'];
			$spawnText = $generatorData['spawnText'];
			$spawnBlock = $generatorData['spawnBlock'];
			$delay = $generatorData['refreshRate'];

			$vector = Utils::stringToVector(":", $generator['position']);
			$position = new Position((float)$vector->x, (float)$vector->y, (float)$vector->z, $this->plugin->getServer()->getLevelByName($this->worldName));

			$this->generators[] = new Generator($item, $delay, $position, $spawnText, $spawnBlock);

		}
	}

	private function initTeams(): void
	{
		foreach ($this->teams as $team) {
			if (count($team->getPlayers()) === 0) {
				$team->updateBedState(false);
			}
		}
	}

	/**
	 * @return array
	 */
	public function getAliveTeams(): array
	{
		$teams = [];
		foreach ($this->teams as $team) {
			if (!$team->hasBed() || count($team->getPlayers()) <= 0) {
				continue;
			}
			$players = [];

			foreach ($team->getPlayers() as $player) {
				if (!$player->isOnline()) {
					continue;
				}
				if ($player->isAlive() && $player->getLevel()->getFolderName() === $this->worldName) {
					$players[] = $player;
				}
			}

			if (count($players) >= 1) {
				$teams[] = $team->getName();
			}

		}
		return $teams;
	}

	public function stop(): void
	{
		foreach (array_merge($this->players, $this->spectators) as $player) {
			$player->getInventory()->clearAll();
			$player->getArmorInventory()->clearAll();
			$player->setGamemode(0);
			$this->cachedPlayers[$player->getRawUniqueId()]->load();

			$player->setFood(20);
			$player->removeAllEffects(true);
			$player->setAllowFlight(false);
			$player->setHealth(20);
			$player->setFood(20);
			$player->setNameTag($player->getName());

			$player->teleport(Server::getInstance()->getDefaultLevel()->getSafeSpawn());
			Scoreboard::remove($player);
			unset($player, $this->plugin->eliminations);
			unset($player, $this->plugin->eliminationsb);
		}

		foreach ($this->teams as $team) {
			$team->reset();
		}

		foreach ($this->generators as $generator) {
			if ($generator->getBlockEntity() !== null) {
				$generator->getBlockEntity()->flagForDespawn();
			}

			if ($generator->getFloatingText() !== null) {
				$generator->getFloatingText()->setInvisible(true);
				foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
					foreach ($generator->getFloatingText()->encode() as $packet) {
						$player->dataPacket($packet);
					}
				}
			}
		}

		$this->spectators = array();
		$this->players = array();
		$this->winnerTeam = '';
		$this->startTime = 60;
		$this->rebootTime = 10;
		$this->generators = array();
		$this->cachedPlayers = array();
		$this->state = self::STATE_LOBBY;
		$this->starting = false;
		$this->plugin->getServer()->unloadLevel($this->plugin->getServer()->getLevelByName($this->worldName));
		$this->reload();


	}

    /**
     * @return string
     */
    public function getLobbyName(): string
    {
        return $this->lobbyName;
    }
}
