<?php /** @noinspection PhpUnused */


namespace BedWars\game;

use BedWars\{BedWars,
	Entity\types\HumanEntity,
	Entity\types\TopsEntity,
	Entity\types\TopsEntitykills,
	utils\Scoreboard
};
use BedWars\game\shop\ItemShop;
use BedWars\game\shop\UpgradeShop;
use BedWars\utils\Utils;
use pocketmine\block\Bed;
use pocketmine\block\Block;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\{PlayerDropItemEvent, PlayerExhaustEvent};
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class GameListener implements Listener
{

	private $plugin;

	public function __construct(BedWars $plugin)
	{
		$this->plugin = $plugin;
	}

	public function onSignChange(SignChangeEvent $event): void
	{
		$player = $event->getPlayer();
		$sign = $event->getBlock();

		if ($event->getLine(0) == "[BedWars]" && $event->getLine(1) !== "") {
			if (!in_array($event->getLine(1), array_keys($this->plugin->games))) {
				$player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena!");
				return;
			}

			$dataFormat = $sign->getX() . ":" . $sign->getY() . ":" . $sign->getZ() . ":" . $player->level->getFolderName();
			$this->plugin->signs[$event->getLine(1)][] = $dataFormat;

			$location = $this->plugin->getDataFolder() . "arenas/" . $event->getLine(1) . ".json";
			if (!is_file($location)) {
				return;
			}

			$fileContent = file_get_contents($location);
			$jsonData = json_decode($fileContent, true);
			$positionData = [
				"signs" => $this->plugin->signs[$event->getLine(1)]
			];

			file_put_contents($location, json_encode(array_merge($jsonData, $positionData)));
			$player->sendMessage(TextFormat::GREEN . "Sign created");

		}
	}

	public function onDamageHuman(EntityDamageByEntityEvent $event)
	{
		if ($event->getEntity() instanceof HumanEntityfbw) {
			$player = $event->getDamager();
			if ($player instanceof Player) {
				$event->setCancelled(true);
				foreach (self::getAllArenas() as $arena) {
					$world = Server::getInstance()->getLevelByName($arena);

					if ($world != null) {

						$game = $this->plugin->games[$arena];
						$game->join($player);
					} else {
						$player->sendMessage(TextFormat::BOLD . TextFormat::GREEN . '»' . TextFormat::RESET . TextFormat::RED . ' There are no sands available at the moment. ' . TextFormat::RED . ' try again…');
					}
				}
			}
		}
	}

	public static function getAllArenas(): array
	{
		$arenas = [];
		if ($handle = opendir(BedWars::getInstance()->getDataFolder() . 'arenas')) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry !== '.' && $entry !== '..') {
					$parsedArena = explode('.', basename($entry));
					$arenas[] = $parsedArena[0];
				}
			}
			closedir($handle);
		}
		return array_filter($arenas);
	}

	public function onExplode(EntityExplodeEvent $ev): void
	{
		$entity = $ev->getEntity();
		if (!$entity instanceof PrimedTNT) return;


	}

	public function onDamageTops(EntityDamageByEntityEvent $event)
	{
		if ($event->getEntity() instanceof TopsEntityfbw) {
			$player = $event->getDamager();
			if ($player instanceof Player) {
				$event->setCancelled(true);
			}
		}
	}

	public function onDamageTopskill(EntityDamageByEntityEvent $event)
	{
		if ($event->getEntity() instanceof TopsEntityfbwkill) {
			$player = $event->getDamager();
			if ($player instanceof Player) {
				$event->setCancelled(true);
			}
		}
	}

	public function onInteract(PlayerInteractEvent $event): void
	{
		$player = $event->getPlayer();
		$block = $event->getBlock();

		foreach ($this->plugin->signs as $arena => $positions) {
			foreach ($positions as $position) {
				$pos = explode(":", $position);
				if ($block->getX() == $pos[0] && $block->getY() == $pos[1] && $block->getZ() == $pos[2] && $player->level->getFolderName() == $pos[3]) {
					$game = $this->plugin->games[$arena];
					$game->join($player);
					return;
				}
			}
		}

		$item = $event->getItem();

		if ($item->getId() == Item::WOOL) {
			$teamColor = Utils::woolIntoColor($item->getDamage());

			$playerGame = $this->plugin->getPlayerGame($player);
			if ($playerGame == null || $playerGame->getState() !== Game::STATE_LOBBY) return;

			if (!$player->hasPermission('lobby.ranked')) {
				$player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "You don't have permission to use this");
				return;
			}

			$playerTeam = $this->plugin->getPlayerTeam($player);
			if ($playerTeam !== null) {
				$player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "You are already in a team!");
				return;
			}

			foreach ($playerGame->teams as $team) {
				if ($team->getColor() == $teamColor) {

					if (count($team->getPlayers()) >= $playerGame->playersPerTeam) {
						$player->sendMessage(BedWars::PREFIX . TextFormat::RED . "Team is full");
						return;
					}
					$team->add($player);
					$player->sendMessage(BedWars::PREFIX . TextFormat::GRAY . "You've joined team " . $teamColor . $team->getName());
				}
			}
		} elseif ($item->getId() == Item::COMPASS) {
			$playerGame = $this->plugin->getPlayerGame($player);
			if ($playerGame == null) return;

			if ($playerGame->getState() == Game::STATE_RUNNING) {
				$playerGame->trackCompass($player);
			} elseif ($playerGame->getState() == Game::STATE_LOBBY) {
				$playerGame->quit($player);
				$player->teleport($this->plugin->getServer()->getDefaultLevel()->getSafeSpawn());
				$player->getInventory()->clearAll();
				$player->getArmorInventory()->clearAll();
				$player->setGamemode(0);
				$player->setFood(20);
				$player->removeAllEffects();
				$player->setAllowFlight(false);
				$player->setHealth(20);
				$player->setFood(20);
				$player->setNameTag($player->getName());
				Scoreboard::remove($player);
				unset($player, $this->plugin->eliminations);
				unset($player, $this->plugin->eliminationsb);
			}
		}
	}

	public function onQuit(PlayerQuitEvent $event): void
	{
		$player = $event->getPlayer();
		foreach ($this->plugin->games as $game) {
			if (in_array($player->getRawUniqueId(), array_keys(array_merge($game->players, $game->spectators)))) {
				$game->quit($player);
				Scoreboard::remove($player);
			}
		}
	}

	public function onEntityLevelChange(EntityLevelChangeEvent $event): void
	{
		$player = $event->getEntity();
		if (!$player instanceof Player) {
			return;
		}

		$playerGame = $this->plugin->getPlayerGame($player);
		if ($playerGame !== null && $event->getTarget()->getFolderName() !== $playerGame->worldName) $playerGame->quit($player);
	}

	public function onMove(PlayerMoveEvent $event): void
	{
		$player = $event->getPlayer();
		foreach ($this->plugin->games as $game) {
			if (isset($game->players[$player->getRawUniqueId()])) {
				if ($game->getState() == Game::STATE_RUNNING) {
					if ($player->getY() < $game->getVoidLimit() && !$player->isSpectator()) {
						$game->killPlayer($player);
						$playerTeam = $this->plugin->getPlayerTeam($player);
						$game->broadcastMessage($playerTeam->getColor() . $player->getName() . " " . TextFormat::GRAY . "was killed by void");
					}
				}
			}
		}
	}

	public function onBreak(BlockBreakEvent $event): void
	{
		$player = $event->getPlayer();
		$block = $event->getBlock();

		if (isset($this->plugin->bedSetup[$player->getRawUniqueId()])) {
			if (!$event->getBlock() instanceof Bed) {
				$player->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "The block is not bed!");
				return;
			}
			$setup = $this->plugin->bedSetup[$player->getRawUniqueId()];

			$step = (int)$setup['step'];

			$location = $this->plugin->getDataFolder() . "arenas/" . $setup['game'] . ".json";
			$fileContent = file_get_contents($location);
			$jsonData = json_decode($fileContent, true);

			$jsonData['teamInfo'][$setup['team']]['bedPos' . $step] = $block->getX() . ":" . $block->getY() . ":" . $block->getZ();
			file_put_contents($location, json_encode($jsonData));

			$player->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Bed $step has been set!");

			if ($step == 2) {
				unset($this->plugin->bedSetup[$player->getRawUniqueId()]);
				return;
			}

			$this->plugin->bedSetup[$player->getRawUniqueId()]['step'] += 1;

			return;
		}

		$playerGame = $this->plugin->getPlayerGame($player);
		if ($playerGame !== null) {
			if ($playerGame->getState() == Game::STATE_LOBBY) {
				$event->setCancelled();
			} elseif ($event->getBlock() instanceof Bed) {
				$blockPos = $event->getBlock()->asPosition();

				$game = $this->plugin->getPlayerGame($player);
				$team = $this->plugin->getPlayerTeam($player);
				if ($team == null || $game == null) return;

				foreach ($game->teamInfo as $name => $info) {
					$bedPos = Utils::stringToVector(":", $info['bedPos1']);
					$teamName = "";

					if ($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z) {
						$teamName = $name;
					} else {
						$bedPos = Utils::stringToVector(":", $info['bedPos2']);
						if ($bedPos->x == $blockPos->x && $bedPos->y == $blockPos->y && $bedPos->z == $blockPos->z) {
							$teamName = $name;
						}
					}

					if ($teamName !== "") {
						$teamObject = $game->teams[$name];
						if ($name == $this->plugin->getPlayerTeam($player)->getName()) {
							$player->sendMessage(TextFormat::RED . "You can't break your bed!");
							$event->setCancelled();
							return;
						}
						$event->setDrops([]);
						$game->breakBed($teamObject, $player);

					}
				}
			} else {
				if ($playerGame->getState() == Game::STATE_RUNNING) {
					if (!in_array(Utils::vectorToString(":", $block->asVector3()), $playerGame->placedBlocks)) {
						$event->setCancelled();
					}
					if ($event->getBlock()->getId() == Block::TNT) {
						$event->getBlock()->ignite();
						$event->getBlock()->getLevel()->setBlock($event->getBlock()->asVector3(), Block::get(Block::AIR));
					}
				}
			}
		}
	}


	public function onEntityExplode(EntityExplodeEvent $event)
	{
		$game = $this->plugin->getGameByMap($event->getEntity()->getLevel()->getFolderName());
		if ($game !== null) {
			$expectedBlocks = [];
			foreach ($event->getBlockList() as $block) {
				if (in_array(Utils::vectorToString(":", $block->asVector3()), $game->placedBlocks, true)) {
					$expectedBlocks[] = $block;
				}
			}
			$event->setBlockList($expectedBlocks);
		}
	}

	public function onPlace(BlockPlaceEvent $event): void
	{
		$player = $event->getPlayer();
		$playerGame = $this->plugin->getPlayerGame($player);
		if ($playerGame !== null) {
			if ($playerGame->getState() == Game::STATE_LOBBY) {
				$event->setCancelled();
			} elseif ($playerGame->getState() == Game::STATE_RUNNING) {
				foreach ($playerGame->teamInfo as $team => $data) {
					$spawn = Utils::stringToVector(":", $data['spawnPos']);
					if ($spawn->distance($event->getBlock()) < 6) {
						$event->setCancelled();
					} else {
						$playerGame->placedBlocks[] = Utils::vectorToString(":", $event->getBlock());
					}
				}

				if ($event->getBlock()->getId() == Block::TNT) {
					$event->getBlock()->ignite();
					$event->getBlock()->getLevel()->setBlock($event->getBlock()->asVector3(), Block::get(Block::AIR));
				}
			}
		}
	}

	public function onPlayerMove(PlayerMoveEvent $event)
	{
		$player = $event->getPlayer();
		$from = $event->getFrom();
		$to = $event->getTo();
		if ($from->distance($to) < 0.1) {
			return;
		}

		foreach ($player->getLevel()->getNearbyEntities($player->getBoundingBox()->expandedCopy(5, 5, 5), $player) as $e) {
			if ($e instanceof Player) {
				continue;
			}

			$xdiff = $player->x - $e->x;
			$zdiff = $player->z - $e->z;
			$angle = atan2($zdiff, $xdiff);
			$yaw = (($angle * 180) / M_PI) - 90;
			$ydiff = $player->y - $e->y;
			$v = new Vector2($e->x, $e->z);
			$dist = $v->distance($player->x, $player->z);
			$angle = atan2($dist, $ydiff);
			$pitch = (($angle * 180) / M_PI) - 90;

			if ($e->namedtag->hasTag("GameEntity")) {
				$e->setRotation($yaw, $pitch);
			}
		}
	}

	public function onDamage(EntityDamageEvent $event): void
	{
		$entity = $event->getEntity();
		foreach ($this->plugin->games as $game) {
			if ($entity instanceof Player && isset($game->players[$entity->getRawUniqueId()])) {

				if ($game->getState() == Game::STATE_LOBBY) {
					$event->setCancelled();
					return;
				}

				if ($event instanceof EntityDamageByEntityEvent) {
					$damager = $event->getDamager();

					if (!$damager instanceof Player) return;

					if (isset($game->players[$damager->getRawUniqueId()])) {
						$damagerTeam = $this->plugin->getPlayerTeam($damager);
						$playerTeam = $this->plugin->getPlayerTeam($entity);

						if ($damagerTeam->getName() == $playerTeam->getName()) {
							$event->setCancelled();
						}
					}
				}

				if ($event->getFinalDamage() >= $entity->getHealth()) {
					$game->killPlayer($entity);
					$event->setCancelled();


				}

			} elseif (isset($game->npcs[$entity->getId()])) {
				$event->setCancelled();

				if ($event instanceof EntityDamageByEntityEvent) {
					$damager = $event->getDamager();

					if ($damager instanceof Player) {
						$npcTeam = $game->npcs[$entity->getId()][0];
						$npcType = $game->npcs[$entity->getId()][1];

						if (($game = $this->plugin->getPlayerGame($damager)) == null) {
							return;
						}

						if ($game->getState() !== Game::STATE_RUNNING) {
							return;
						}

						$playerTeam = $this->plugin->getPlayerTeam($damager)->getName();
						if ($npcTeam !== $playerTeam && $npcType == "upgrade") {
							$damager->sendMessage(TextFormat::RED . "You can upgrade only your base!");
							return;
						}

						if ($npcType == "upgrade") {
							UpgradeShop::sendDefaultShop($damager);
						} else {
							ItemShop::sendDefaultShop($damager);
						}
					}
				}
			}
		}
	}

	public function setHunger(PlayerExhaustEvent $event)
	{
		foreach ($this->plugin->games as $game) {

			if ($game->getState() == Game::STATE_LOBBY) {
				$event->setCancelled(true);
			} else {
				$event->setCancelled(false);
			}
		}
	}

	public function onDrop(PlayerDropItemEvent $event)
	{
		$player = $event->getPlayer();
		foreach ($this->plugin->games as $game) {
			if ($game->getState() == Game::STATE_LOBBY) {
				$event->setCancelled(true);
			}
			if ($player->getGamemode() == 3) {
				$event->setCancelled(true);
			}
		}
	}

	public function onCommandPreprocess(PlayerCommandPreprocessEvent $event): void
	{
		$player = $event->getPlayer();

		$game = $this->plugin->getPlayerGame($player);

		if ($game == null) return;

		$args = explode(" ", $event->getMessage());

		if ($args[0] == '/fly' || isset($args[1]) && $args[1] == 'join') {
			$player->sendMessage(TextFormat::RED . "You cannot run this in-game!");
			$event->setCancelled();
		}
		if ($args[0] == '/hub' || isset($args[1]) && $args[1] == 'join') {
			$player->sendMessage(TextFormat::RED . "You cannot run this in-game!");
			$event->setCancelled();
		}
		if ($args[0] == '/kill' || isset($args[1]) && $args[1] == 'join') {
			$player->sendMessage(TextFormat::RED . "You cannot run this in-game!");
			$event->setCancelled();
		}
		if ($args[0] == '/bedwars quit' || isset($args[1]) && $args[1] == 'join') {
			$player->sendMessage(TextFormat::RED . "You cannot run this in-game!");
			$event->setCancelled();
		}
	}


	/**
	 * @param DataPacketReceiveEvent $event
	 */
	public function handlePacket(DataPacketReceiveEvent $event): void
	{
		$packet = $event->getPacket();
		$player = $event->getPlayer();


		if ($packet instanceof ModalFormResponsePacket) {
			$playerGame = $this->plugin->getPlayerGame($player);
			if ($playerGame == null) return;
			$data = json_decode($packet->formData);
			if (is_null($data)) {
				return;
			}
			if ($packet->formId == 50) {
				ItemShop::sendPage($player, intval($data));
			} elseif ($packet->formId < 100) {
				ItemShop::handleTransaction(($packet->formId), json_decode($packet->formData), $player, $this->plugin, (int)$packet->formId);
			} elseif ($packet->formId == 100) {
				UpgradeShop::sendBuyPage(json_decode($packet->formData), $player, $this->plugin);
			} elseif ($packet->formId > 100) {
				UpgradeShop::handleTransaction(($packet->formId), $player, $this->plugin);
			}
		}
	}
}