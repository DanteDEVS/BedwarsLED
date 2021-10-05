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
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

use pocketmine\item\Armor;
use pocketmine\item\Sword;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\level\sound\PopSound;
use pocketmine\level\sound\EndermanTeleportSound;
use libs\muqsit\invmenu\InvMenu;
use libs\muqsit\invmenu\InvMenuHandler;

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
		if ($event->getEntity() instanceof HumanEntity) {
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
		if ($event->getEntity() instanceof TopsEntity) {
			$player = $event->getDamager();
			if ($player instanceof Player) {
				$event->setCancelled(true);
			}
		}
	}

	public function onDamageTopskill(EntityDamageByEntityEvent $event)
	{
		if ($event->getEntity() instanceof TopsEntitykill) {
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
                                $player->sendMessage("§a----------------------------
                                            \n§c§lBed§l§fWars Dctxdev Test : 
                                            \n§e§lProtect your bed and destroy the enemy beds
                                            \nUpgrade yourself and your team by collecting
                                            \nIron , Gold , Emerald and Diamond from the generators 
                                            \nto access powerful upgrade  
                                            \n§a----------------------------\n
                                            §c§lCross-Teaming with other teams is not allowed on this game.
                                ");
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
					            $this->shopWindows($damager);
						} else {
					             $this->shopWindows($damager);
						}
					}
				}
			}
		}
	}

	    public function shopWindows(Player $damager) {
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->readonly();
        $menu->setName("Shop Item");
        $menu->setListener([$this, "transactionZone"]);
        $inv = $menu->getInventory();
        $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
        $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
        $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
        $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
        $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
        $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
        $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
        $inv->setItem(19, Item::get(35, 14, 16)->setCustomName("§eWool")->setLore(["\n§f4 Iron"]));
        $inv->setItem(20, Item::get(159, 14, 16)->setCustomName("§eTerracotta")->setLore(["\n§f12 Iron"]));
        $inv->setItem(21, Item::get(95, 6, 4)->setCustomName("§eBlast Protection Glass")->setLore(["\n§f12 Iron"]));
        $inv->setItem(22, Item::get(121, 0, 12)->setCustomName("§eEnd Stone")->setLore(["\n§f24 Iron"]));
        $inv->setItem(23, Item::get(65, 0, 16)->setCustomName("§eLadder")->setLore(["\n§f4 Iron"]));
        $inv->setItem(24, Item::get(5, 0, 16)->setCustomName("§eOak Wood")->setLore(["\n§64 Gold"]));
        $inv->setItem(25, Item::get(49, 0, 4)->setCustomName("§eObsidian")->setLore(["\n§24 Emerlad"]));
        $menu->send($damager);
    }
	
        //TransactionZone
    public function transactionZone(Player $sender, Item $item){
        $hand = $sender->getInventory()->getItemInHand()->getCustomName();
        //MenuZone//
        ////////////////////
        //Block Menu
        if($item->getId() == 159 && $item->getDamage() == 0){
            $block = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $block->readonly();
            $block->setName("Shop Item");
            $block->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $block->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(35, 14, 16)->setCustomName("§eWool")->setLore(["\n§f4 Iron"]));
            $inv->setItem(20, Item::get(159, 14, 16)->setCustomName("§eTerracotta")->setLore(["\n§f12 Iron"]));
            $inv->setItem(21, Item::get(95, 6, 4)->setCustomName("§eBlast Protection Glass")->setLore(["\n§f12 Iron"]));
            $inv->setItem(22, Item::get(121, 0, 12)->setCustomName("§eEnd Stone")->setLore(["\n§f24 Iron"]));
            $inv->setItem(23, Item::get(65, 0, 16)->setCustomName("§eLadder")->setLore(["\n§f4 Iron"]));
            $inv->setItem(24, Item::get(5, 0, 16)->setCustomName("§eOak Wood")->setLore(["\n§64 Gold"]));
            $inv->setItem(25, Item::get(49, 0, 4)->setCustomName("§eObsidian")->setLore(["\n§24 Emerlad"]));
            $block->send($sender);
        }
        //End Block Menu
        ////////////////////
        //Male Menu
        if($item->getId() == 283 && $item->getDamage() == 0){
            $male = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $male->readonly();
            $male->setName("Shop Item");
            $male->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $male->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(272, 0, 1)->setCustomName("§eStone Sword")->setLore(["\n§f10 Iron"]));
            $inv->setItem(20, Item::get(267, 0, 1)->setCustomName("§eIron Sword")->setLore(["\n§67 Gold"]));
            $inv->setItem(21, Item::get(276, 0, 1)->setCustomName("§eDiamond Sword")->setLore(["\n§24 Emerlad"]));
            //Variable Enchantment Punch I
            $punch = Enchantment::getEnchantment(19);
            $punchIns = new EnchantmentInstance($punch, 1);
            //Variable Item Stick
            $stick = Item::get(280, 0, 1);
            //Variable Item Stick AddEnchantment
            $stick->addEnchantment($punchIns);
            $inv->setItem(22, ($stick)->setCustomName("§eKnockback Stick")->setLore(["\n§65 Gold"]));
            $male->send($sender);
        }
        //End Male Zone
        ////////////////////
        //Armor Menu
        if($item->getId() == 301 && $item->getDamage() == 0){
            $armor = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $armor->readonly();
            $armor->setName("Shop Item");
            $armor->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $armor->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(305, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(305, 1, 1)->setCustomName("§ePermanen Armor")->setLore(["\n§f10 Iron"]));
            $inv->setItem(20, Item::get(309, 0, 1)->setCustomName("§ePermanen Armor Iron")->setLore(["\n§67 Gold"]));
            $inv->setItem(21, Item::get(313, 0, 1)->setCustomName("§ePermanen Armor Diamon")->setLore(["\n§24 Emerlad"]));
            $armor->send($sender);
        }
        //End Armor Zone
        ////////////////////
        //Tools Menu
        if($item->getId() == 274 && $item->getDamage() == 0){
            $tools = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $tools->readonly();
            $tools->setName("Shop Item");
            $tools->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $tools->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(359, 0, 1)->setCustomName("§ePermanen Shears")->setLore(["\n§f20 Iron"]));
            $inv->setItem(20, Item::get(270, 1, 1)->setCustomName("§eWood Pickaxe")->setLore(["\n§f10 Iron"]));
            $inv->setItem(21, Item::get(274, 0, 1)->setCustomName("§eStone Pickaxe")->setLore(["\n§f20 Iron"]));
            $inv->setItem(22, Item::get(257, 0, 1)->setCustomName("§eIron Pickaxe")->setLore(["\n§610 Gold"]));
            $inv->setItem(23, Item::get(278, 0, 1)->setCustomName("§eDiamond Pickaxe")->setLore(["\n§615 Gold"]));
            $inv->setItem(24, Item::get(279, 0, 1)->setCustomName("§eDiamond Axe")->setLore(["\n§612 Gold"]));
            $tools->send($sender);
        }
        //End Tools Menu
        ////////////////////
        //Bow & Arrow Menu
        if($item->getId() == 261 && $item->getDamage() == 0){
            $arwow = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $arwow->readonly();
            $arwow->setName("Shop Item");
            $arwow->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $arwow->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(262, 0, 8)->setCustomName("§eArrow")->setLore(["\n§62 Gold"]));
            $inv->setItem(20, Item::get(261, 1, 1)->setCustomName("§eBow")->setLore(["\n§612 Gold"]));
            //Variable Enchantment Power I && Punch I
            $power = Enchantment::getEnchantment(19);
            $punch = Enchantment::getEnchantment(20);
            $powerIns = new EnchantmentInstance($power, 1);
            $punchIns = new EnchantmentInstance($punch, 1);
            //Variable Item Bow
            $bowpower = Item::get(261, 2, 1);
            $bowpower2 = Item::get(261, 3, 1);
            //Variable Item Bow AddEnchantment
            $bowpower->addEnchantment($powerIns);
            $bowpower2->addEnchantment($powerIns);
            $bowpower2->addEnchantment($punchIns);
            $inv->setItem(21, ($bowpower)->setCustomName("§eBow (Power I)")->setLore(["\n§6124 Gold"]));
            $inv->setItem(22, ($bowpower2)->setCustomName("§eBow (Power I, Punch I)")->setLore(["\n§25 Emerlad"]));
            $arwow->send($sender);
        }
        //End Bow & Arrow Menu
        ////////////////////
        //Potion Menu
        if($item->getId() == 117 && $item->getDamage() == 0){
            $potion = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $potion->readonly();
            $potion->setName("Shop Item");
            $potion->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $potion->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(373, 14, 1)->setCustomName("§eSpeed Potion (45 Second)")->setLore(["\n§21 Emerlad"]));
            $inv->setItem(20, Item::get(373, 9, 1)->setCustomName("§eJump Potion II (45 Second)")->setLore(["\n§21 Emerlad"]));
            $inv->setItem(21, Item::get(373, 7, 1)->setCustomName("§eInvisible Potion (30 Second)")->setLore(["\n§22 Emerlad"]));
            $potion->send($sender);
        }
        //End Potion Menu
        ////////////////////
        //Utility Menu
        if($item->getId() == 46 && $item->getDamage() == 0){
            $util = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
            $util->readonly();
            $util->setName("Shop Item");
            $util->setListener([$this, "transactionZone"]);
            $sender->getLevel()->addSound(new PopSound($sender));
            $inv = $util->getInventory();
            $inv->setItem(1, Item::get(159, 0, 1)->setCustomName("§fBlocks"));
            $inv->setItem(2, Item::get(283, 0, 1)->setCustomName("§fMale"));
            $inv->setItem(3, Item::get(301, 0, 1)->setCustomName("§fArmor"));
            $inv->setItem(4, Item::get(274, 0, 1)->setCustomName("§fTools"));
            $inv->setItem(5, Item::get(261, 0, 1)->setCustomName("§fBow & Arrow"));
            $inv->setItem(6, Item::get(117, 0, 1)->setCustomName("§fPotion"));
            $inv->setItem(7, Item::get(46, 0, 1)->setCustomName("§fUtility"));
            $inv->setItem(19, Item::get(322, 0, 1)->setCustomName("§eGolden Apple")->setLore(["\n§63 Gold"]));
            $inv->setItem(20, Item::get(332, 0, 1)->setCustomName("§eBedbug")->setLore(["\n§f40 Iron"]));
            $inv->setItem(21, Item::get(383, 93, 1)->setCustomName("§eDream Defender")->setLore(["\n§f120 Iron"]));
            $inv->setItem(22, Item::get(385, 0, 1)->setCustomName("§eFireball")->setLore(["\n§f40 Iron"]));
            $inv->setItem(23, Item::get(46, 0, 1)->setCustomName("§eTNT")->setLore(["\n§64 Gold"]));
            $inv->setItem(24, Item::get(368, 0, 1)->setCustomName("§eEnderpearl")->setLore(["\n§24 Emerald"]));
            $inv->setItem(25, Item::get(345, 0, 1)->setCustomName("§ePlayer Tracker")->setLore(["\n§23 Emerald"]));
            $inv->setItem(28, Item::get(344, 0, 1)->setCustomName("§eBridge Egg")->setLore(["\n§22 Emerlad"]));
            $inv->setItem(29, Item::get(335, 0, 1)->setCustomName("§eMagick Milk")->setLore(["\n§64 Gold"]));
            $util->send($sender);
        }
        //TransactionZone//
        ////////////////////
        //Block Zone
        ////////////////////
        //Wool
        if($item->getId() == 35 && $item->getDamage() == 14){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 4));
                $inv->addItem(Item::get(35, 14, 16));
                $sender->sendMessage("§aYou bought §e16x §afor §f4 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Terracotta
        if($item->getId() == 159 && $item->getDamage() == 14){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 12);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 12));
                $inv->addItem(Item::get(159, 14, 16));
                $sender->sendMessage("§aYou bought §e16x §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Blast Protection Glass
        if($item->getId() == 95 && $item->getDamage() == 6){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 5);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 24));
                $inv->addItem(Item::get(95, 6, 4));
                $sender->sendMessage("§aYou bought §e6x §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Stone
        if($item->getId() == 121 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 5);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 24));
                $inv->addItem(Item::get(121, 0, 12));
                $sender->sendMessage("§aYou bought §e16x §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Ladder
        if($item->getId() == 65 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 4));
                $inv->addItem(Item::get(65, 0, 16));
                $sender->sendMessage("§aYou bought §e16x §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Oak Wood Plank
        if($item->getId() == 5 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 4));
                $inv->addItem(Item::get(5, 0, 16));
                $sender->sendMessage("§aYou bought §e16x §afor §64 gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Obsidian
        if($item->getId() == 49 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 4));
                $inv->addItem(Item::get(49, 0, 4));
                $sender->sendMessage("§aYou bought §e16x §afor §24 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Block Zone
        ////////////////////
        //Male Zone
        //Stone Sword
        if($item->getId() == 272 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 10);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 10));
                $inv->removeItem(Item::get(268, 0, 1));//Remove Wooden Sword
                $inv->addItem(Item::get(272, 0, 1));
                $sender->sendMessage("§aYou bought §eStone sword §afor §f10 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Iron Sword
        if($item->getId() == 267 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 7);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 7));
                $inv->removeItem(Item::get(268, 0, 1));//Remove Wooden Sword
                $inv->removeItem(Item::get(272, 0, 1));//Remove Stone Sword
                $inv->addItem(Item::get(267, 0, 1));
                $sender->sendMessage("§aYou bought §eIron sword §afor §67 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Diamond Sword
        if($item->getId() == 276 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 4));
                $inv->removeItem(Item::get(268, 0, 1));//Remove Wooden Sword
                $inv->removeItem(Item::get(272, 0, 1));//Remove Stone Sword
                $inv->removeItem(Item::get(267, 0, 1));//Remove Iron Sword
                $inv->addItem(Item::get(276, 0, 1));
                $sender->sendMessage("§aYou bought §eDiamond sword §afor §24 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Kbockback Stick
        if($item->getId() == 280 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 5);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 5));
                $punch = Enchantment::getEnchantment(19);
                $punchIns = new EnchantmentInstance($punch, 1);
                $stick = Item::get(280, 0, 1);
                $stick->addEnchantment($punchIns);
                $inv->addItem($stick);
                $sender->sendMessage("§aYou bought §eKbockback stick §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Male Zone
        ////////////////////
        //Armor Zone
        ////////////////////
        //Permanen Armor
        if($item->getId() == 305 && $item->getDamage() == 1){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 40);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 40));
                $sender->getArmorInventory()->clearAll();
                $sender->getArmorInventory()->setLeggings(Item::get(304, 0, 1));
                $sender->getArmorInventory()->setBoots(Item::get(305, 0, 1));
                $sender->sendMessage("§aYou bought §ePermanen armor §afor §f140 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Permanen Armor Iron
        if($item->getId() == 309 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 12);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 12));
                $sender->getArmorInventory()->clearAll();
                $sender->getArmorInventory()->setLeggings(Item::get(308, 0, 1));
                $sender->getArmorInventory()->setBoots(Item::get(309, 0, 1));
                $sender->sendMessage("§aYou bought §ePermanen armor iron §afor §612 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Permanen Armor Diamond
        if($item->getId() == 313 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 6);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 6));
                $sender->getArmorInventory()->clearAll();
                $sender->getArmorInventory()->setLeggings(Item::get(312, 0, 1));
                $sender->getArmorInventory()->setBoots(Item::get(313, 0, 1));
                $sender->sendMessage("§aYou bought §ePermanen armor diamond §afor §26 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Armor Zone
        ////////////////////
        //Tools Zone
        ////////////////////
        //Permanen Shears
        if($item->getId() == 359 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 20);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 20));
                $inv->addItem(Item::get(359, 0, 1));
                $sender->sendMessage("§aYou bought §ePermanen shears §afor §f20 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Wood Pickaxe
        if($item->getId() == 270 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 10);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 10));
                $inv->addItem(Item::get(270, 0, 1));
                $sender->sendMessage("§aYou bought §eWood pickaxe §afor §f10 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Stone Pickaxe
        if($item->getId() == 274 && $item->getDamage() == 1){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 20);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 20));
                $inv->addItem(Item::get(274, 0, 1));
                $sender->sendMessage("§aYou bought §e16x §afor §f20 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Iron Pickaxe
        if($item->getId() == 257 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 10);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 10));
                $inv->addItem(Item::get(257, 0, 1));
                $sender->sendMessage("§aYou bought §eIron pickaxe §afor §610 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Diamond Pickaxe
        if($item->getId() == 278 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 15);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 15));
                $inv->addItem(Item::get(278, 0, 1));
                $sender->sendMessage("§aYou bought §eDiamond pickaxe §afor §615 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Diamond Axe
        if($item->getId() == 279 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 12);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 12));
                $inv->addItem(Item::get(279, 0, 1));
                $sender->sendMessage("§aYou bought §eDiamond axe §afor §612 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Tools Zone
        ////////////////////
        //Bow Zone
        ////////////////////
        //Arrow
        if($item->getId() == 262 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 2);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 2));
                $inv->addItem(Item::get(262, 0, 8));
                $sender->sendMessage("§aYou bought §eBow §afor §62 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Bow
        if($item->getId() == 261 && $item->getDamage() == 1){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 12);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 12));
                $inv->addItem(Item::get(261, 0, 1));
                $sender->sendMessage("§aYou bought §eBow §afor §612 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Bow Power I
        if($item->getId() == 261 && $item->getDamage() == 2){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 24);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 24));
                $power = Enchantment::getEnchantment(19);
                $powerIns = new EnchantmentInstance($power, 1);
                $bowpower = Item::get(261, 0, 1);
                $bowpower->addEnchantment($powerIns);
                $inv->addItem($bowpower);
                $sender->sendMessage("§aYou bought §eBow power I §afor §624 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Bow Power I Punch I
        if($item->getId() == 261 && $item->getDamage() == 3){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 5);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 5));
                $power = Enchantment::getEnchantment(19);
                $punch = Enchantment::getEnchantment(20);
                $powerIns = new EnchantmentInstance($power, 1);
                $punchIns = new EnchantmentInstance($punch, 1);
                $bowpower = Item::get(261, 0, 1);
                $bowpower->addEnchantment($powerIns);
                $bowpower->addEnchantment($punchIns);
                $inv->addItem($bowpower);
                $sender->sendMessage("§aYou bought §eBow power I punch I §afor §25 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Bow Zone
        ////////////////////
        //Potion Zone
        ////////////////////
        //Potion Speed
        if($item->getId() == 373 && $item->getDamage() == 14){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 1);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 1));
                $inv->addItem(Item::get(373, 14, 1));
                $sender->sendMessage("§aYou bought §ePotion speed §afor §21 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Potion Jump
        if($item->getId() == 373 && $item->getDamage() == 9){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 1);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 1));
                $inv->addItem(Item::get(373, 9, 1));
                $sender->sendMessage("§aYou bought §ePotion jump §afor §21 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Potion Invisible
        if($item->getId() == 373 && $item->getDamage() == 7){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 2);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 2));
                $inv->addItem(Item::get(373, 7, 1));
                $sender->sendMessage("§aYou bought §ePotion invisible §afor §22 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //End Potion Zone
        ////////////////////
        //Utility Zone
        ////////////////////
        //Golden Apple
        if($item->getId() == 322 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 3);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 3));
                $inv->addItem(Item::get(322, 0, 1));
                $sender->sendMessage("§aYou bought §eGolden apple §afor §612 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //BedBug
        if($item->getId() == 332 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 40);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 40));
                $inv->addItem(Item::get(332, 0, 1));
                $sender->sendMessage("§aYou bought §eBedbug §afor §f40 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Dream Defender
        if($item->getId() == 383 && $item->getDamage() == 95){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 120);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 120));
                $inv->addItem(Item::get(383, 95, 1));
                $sender->sendMessage("§aYou bought §eDream defender §afor §f120 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Fireball
        if($item->getId() == 385 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(265, 0, 40);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(265, 0, 40));
                $inv->addItem(Item::get(385, 0, 1));
                $sender->sendMessage("§aYou bought §eFireball §afor §f12 Iron");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour iron not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //TNT
        if($item->getId() == 46 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 4));
                $inv->addItem(Item::get(46, 0, 1));
                $sender->sendMessage("§aYou bought §eTNT §afor §64 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Ender Pearl
        if($item->getId() == 368 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 4));
                $inv->addItem(Item::get(368, 0, 1));
                $sender->sendMessage("§aYou bought §e1Ender pearl §afor §f12 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Player Tracker
        if($item->getId() == 345 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(452, 0, 24);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(452, 0, 24));
                $inv->addItem(Item::get(345, 0, 1));
                $sender->sendMessage("§aYou bought §ePlayer tracker §afor §23 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cThis item on progres");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Bridge Egg
        if($item->getId() == 344 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(388, 0, 2);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(388, 0, 4));
                $inv->addItem(Item::get(344, 0, 1));
                $sender->sendMessage("§aYou bought §eBridge egg §afor §22 Emerald");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour emerald not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
        //Magic Milk
        if($item->getId() == 335 && $item->getDamage() == 0){
            $inv = $sender->getInventory();
            $item = Item::get(266, 0, 4);
            if($inv->contains($item)){
                $inv->removeItem(Item::get(266, 0, 4));
                $inv->addItem(Item::get(335, 0, 1));
                $sender->sendMessage("§aYou bought §eMagick milk §afor §64 Gold");
                $sender->getLevel()->addSound(new PopSound($sender));
            }else{
                $sender->sendMessage("§cYour gold not enough");
                $sender->getLevel()->addSound(new EndermanTeleportSound($sender));
            }
        }
    }
    //End TransactionZone
    ////////////////////	
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
