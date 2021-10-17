<?php

/**
 * Copyright 2018-2020 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\BedWars\arena;

use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\block\Block;
use pocketmine\block\{Bed};
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\network\mcpe\protocol\{LevelSoundEventPacket, LevelSoundEvent};
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\event\entity\{EntityExplodeEvent};
use pocketmine\utils\Config;
use pocketmine\inventory\{PlayerInventory, EnderChestInventory, ChestInventory, transaction\action\SlotChangeAction};
use pocketmine\event\Listener;
use vixikhd\BedWars\libs\muqsit\invmenu\inventory\InvMenuInventory;
use pocketmine\event\player\{PlayerChatEvent};
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\network\mcpe\protocol\OnScreenTextureAnimationPacket;
use pocketmine\event\player\{PlayerRespawnEvent, PlayerDropItemEvent};
use pocketmine\event\entity\{
    EntityMotionEvent,
    EntityDamageEvent,
    ProjectileHitEntityEvent
};
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\{
    BlockPlaceEvent,
    BlockUpdateEvent,
    LeavesDecayEvent
};
use pocketmine\event\inventory\{
    InventoryPickupItemEvent,
    InventoryTransactionEvent,
    InventoryOpenEvent,
    InventoryCloseEvent
};
use pocketmine\item\enchantment\{Enchantment, EnchantmentInstance};
use pocketmine\item\{Armor, Sword, Item, Pickaxe, Axe};
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\MobArmorEquipmentPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\entity\{object\ItemEntity, Effect, Entity};
use pocketmine\entity\EffectInstance;
use pocketmine\level\{particle\DestroyBlockParticle, Level};
use pocketmine\level\Position;
use pocketmine\utils\Color;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\StopSoundPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\{Furnace, Skull, EnchantTable};
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\tile\Tile;
use vixikhd\BedWars\math\{
    Vector3,
    Generator,
    Dinamite as TNT,
    Bedbug,
    Golem,
    Fireball
};
use vixikhd\BedWars\BedWars;
use fw\Fireworks;
use vixikhd\BedWars\ServerManager;
use vixikhd\BedWars\libs\muqsit\invmenu\{InvMenu};
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\ByteArrayTag;
use vixikhd\BedWars\libs\muqsit\invmenu\transaction\{
    DeterministicInvMenuTransaction,
    InvMenuTransaction,
    InvMenuTransactionResult
};

use xenialdan\MagicWE2\helper\SessionHelper;
use xenialdan\MagicWE2\API;
use xenialdan\MagicWE2\Loader as WE;
use xenialdan\MagicWE2\selection\Selection;
use pocketmine\utils\Random;

use Scoreboards\Scoreboards;
/**
 * Class Arena
 * @package BedWars\arena
 */
class Arena implements Listener
{
    const MSG_MESSAGE = 0;
    const MSG_TIP = 1;
    const MSG_POPUP = 2;
    const MSG_TITLE = 3;

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    /** @var BedWars $plugin */
    public $plugin;

    /** @var ArenaScheduler $scheduler */
    public $scheduler;

    /** @var MapReset $mapReset */
    public $mapReset;

    /** @var int $phase */
    public $phase = 0;

    public $kill = [];
    public $finalkill = [];
    public $broken = [];

    /** @var array $data */
    public $data = [];
    public $placedBlock = [];

    public $redteam = [];
    public $blueteam = [];

    public $invis = [];
    public $inChest = [];

    public $teamgenerator = [];
    public $teamprotection = [];
    public $teamsharpness = [];
    public $teamhaste = [];
    public $teamhealth = [];
    public $countertrap = [];
    public $itstrap = [];
    public $minertrap = [];
    public $alarmtrap = [];

    public $armor = [];
    public $axe = [];
    public $pickaxe = [];
    public $shear = [];
    public $traps = [];
    public $shop;
    public $upgrade;

    /** @var bool $setting */
    public $setup = false;

    /** @var Player[] $players */
    public $players = [];
    public $index = [];

    public $ghost = [];

    /** @var Player[] $toRespawn */
    public $toRespawn = [];

    /** @var Level $level */
    public $level = null;

    public $respawn = [];
    public $respawnC = [];
    public $milk = [];

    /**
     * Arena constructor.
     * @param BedWars $plugin
     * @param array $arenaFileData
     */
    public function __construct(BedWars $plugin, array $arenaFileData)
    {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(false);

        $this->plugin
            ->getScheduler()
            ->scheduleRepeatingTask(
                $this->scheduler = new ArenaScheduler($this),
                20
            );

        if ($this->setup) {
            if (empty($this->data)) {
                $this->createBasicData();
            }
        } else {
            $this->loadArena();
        }
    }

    public function setColorTag($player)
    {
        $color = [
            "red" => "§c",
            "blue" => "§9",
        ];
        $player->setDisplayName(
            "" .
                $color[$this->getTeam($player)] .
                "" .
                $player->getDisplayName() .
                ""
        );
    }

    public function setTeam($player, $index)
    {
        if (in_array($index, [1, 2, 3, 4])) {
            $this->redteam[$player->getName()] = $player;
        }
        if (in_array($index, [5, 6, 7, 8])) {
            $this->blueteam[$player->getName()] = $player;
        }
    }

    public function selectTeam($player)
    {
        $api = $player
            ->getServer()
            ->getPluginManager()
            ->getPlugin("FormAPI");
        $form = $api->createSimpleForm(function (
            Player $player,
            int $data = null
        ) {
            $result = $data;
            if ($result === null) {
                return true;
            }
            switch ($result) {
                case 0:
                    if (count($this->redteam) == 4) {
                        $player->sendMessage("§cThis team is full");
                    } else {
                        $player->sendMessage(
                            "§eMaintenance");
                    }
                    break;
                case 1:
                    if (count($this->blueteam) == 4) {
                        $player->sendMessage("§cThis team is full");
                    } else {
                        $player->sendMessage(
                            "§eMaintenance");
                    }
                    break;
                case 4:
                    break;
            }
        });
        $form->setTitle("Select Team");
        $form->addButton("§cRED§r\n" . count($this->redteam));
        $form->addButton("§1BLUE§r\n" . count($this->blueteam));
        $form->addButton("CLOSE", 0, "textures/blocks/barrier");
        $form->sendToPlayer($player);
        return $form;
    }

    public function treasureStatus($team)
    {
        $status = null;
        $vc = Vector3::fromString($this->data["treasure"][$team]);
        if (
            ($tr = $this->level->getBlockAt(
                $vc->x,
                $vc->y,
                $vc->z
            ))->getId() !== 0
        ) {
            $status = "§aBed Alive";
        } else {
            $status = "§cBed Broken";
        }
        return $status;
    }

    public function teamStatus($team)
    {
        $status = null;
        $vc = Vector3::fromString($this->data["treasure"][$team]);
        if (
            ($tr = $this->level->getBlockAt($vc->x, $vc->y, $vc->z)) instanceof
            Bed
        ) {
            $status = "§l§a§r"; //✓
        } else {
            $count = 0;
            foreach ($this->players as $mate) {
                if ($this->getTeam($mate) == $team) {
                    $count++;
                }
            }
            if ($count <= 0) {
                $status = "§l§c§r"; //x
            } else {
                $status = "§e" . $count . "";
            }
        }
        return $status;
    }

    public function getTeam($player)
    {
        if (isset($this->redteam[$player->getName()])) {
            return "red";
        }
        if (isset($this->blueteam[$player->getName()])) {
            return "blue";
        }
        return "";
    }

    public function hasTreasure($player)
    {
        $team = $this->getTeam($player);
        $vc = Vector3::fromString($this->data["treasure"][$team]);
        $alive = false;
        if (
            ($tr = $this->level->getBlockAt($vc->x, $vc->y, $vc->z)) instanceof
            Bed
        ) {
            $alive = true;
        }
        return $alive;
    }

    /**
     * @param Player $player
     */
    public function joinToArena(Player $player)
    {
        if (!$this->data["enabled"]) {
            $player->sendMessage("§ccant join arena");
            return;
        }

        if (count($this->players) >= $this->data["slots"]) {
            $player->sendMessage("§carena is full");
            return;
        }

        if ($this->inGame($player)) {
            $player->sendMessage("§cyou already in game");
            return;
        }

        $selected = false;
        for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
            if(!$selected) {
                if(!isset($this->players[$index = "spawn-{$lS}"])) {
                    $player->teleport(Position::fromObject(Vector3::fromString($this->data["lobby"]), $this->level));
                    $this->setTeam($player, $lS);
                    $this->players[$index] = $player;
                    $this->index[$player->getName()] = $index;
                    $selected = true;
                }
            }
        }
        $player->removeAllEffects();
        $this->kill[$player->getName()] = 0;
        $this->finalkill[$player->getId()] = 0;
        $this->broken[$player->getId()] = 0;
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getEnderChestInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setAbsorption(0);
        $player
            ->getInventory()
            ->setItem(
                8,
                Item::get(355, 14, 1)->setCustomName("§dBack to hub §7[use]")
            );
        $player
            ->getInventory()
            ->setItem(0, Item::get(145, 0, 1)->setCustomName("§bChoseTeam"));
        $player->setGamemode($player::ADVENTURE);
        $player->setHealth(20);
        $player->setFood(20);
        $player->setNameTagVisible();
        $this->broadcastMessage(
            "§7{$player->getDisplayName()} joined. §8[" .
                count($this->players) .
                "/{$this->data["slots"]}]"
        );
    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     */
    public function disconnectPlayer(
        Player $player,
        string $quitMsg = "",
        bool $death = false
    ) {
        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
            $this->plugin->resetTopFinalKill($player);
                $index = "";
                foreach ($this->players as $i => $pl) {
                    if ($pl->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if ($index != "") {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }
        $api = Scoreboards::getInstance();
        $api->remove($player);
        $player->removeAllEffects();
        $player->setGamemode($this->plugin->getServer()->getDefaultGamemode());
        $player->setHealth(20);
        $player->setFood(20);
        $player->setAbsorption(0);
        $player->setScoreTag("");
        $this->plugin->resetTopFinalKill($player);
        if ($this->plugin->config["waterdog"]["enabled"]) {
            ServerManager::transferPlayer(
                $player,
                $this->plugin->config["waterdog"]["lobbyServer"]
            );
        }
        unset($this->kill[$player->getName()]);
        if (isset($this->broken[$player->getId()])) {
            unset($this->broken[$player->getId()]);
        }
        if (isset($this->finalkill[$player->getId()])) {
            unset($this->finalkill[$player->getId()]);
        }
        unset($this->index[$player->getName()]);
        if (isset($this->plugin->lastDamager[$player->getName()])) {
            unset($this->plugin->lastDamager[$player->getName()]);
            unset($this->plugin->lastTime[$player->getName()]);
            unset($this->plugin->damaged[$player->getName()]);
        }
        if (isset($this->respawn[$player->getName()])) {
            unset($this->respawn[$player->getName()]);
            unset($this->respawnC[$player->getName()]);
        }
        if (isset($this->invis[$player->getId()])) {
            $this->setInvis($player, false);
        }
        $player->setDisplayName($player->getName());
        $player->setScoreTag("");
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $team = $this->getTeam($player);
        if ($this->phase == self::PHASE_GAME) {
            $count = 0;
            foreach ($this->players as $mate) {
                if ($this->getTeam($mate) == $team) {
                    $count++;
                }
            }
            if ($count <= 0) {
                $spawn = Vector3::fromString($this->data["treasure"][$team]);
                foreach ($this->level->getEntities() as $g) {
                    if ($g instanceof Generator) {
                        if ($g->asVector3()->distance($spawn) < 20) {
                            $g->close();
                        }
                    }
                }
                $this->destroyTreasure($team);
                $this->plugin->resetTopFinalKill($player);
                $color = [
                    "red" => "§cRed",
                    "blue" => "§9Blue",
                ];
                $this->broadcastMessage(
                    "§l§fTEAM ELIMINATED §r$color[$team] §fwas §celimiated!"
                );
            }
            if ($team == "red") {
                unset($this->redteam[$player->getName()]);
            }
            if ($team == "blue") {
                unset($this->blueteam[$player->getName()]);
            }
        }
        if (isset($this->redteam[$player->getName()])) {
            unset($this->redteam[$player->getName()]);
        }
        if (isset($this->blueteam[$player->getName()])) {
            unset($this->blueteam[$player->getName()]);
        }
        if (isset($this->armor[$player->getName()])) {
            unset($this->armor[$player->getName()]);
        }
        if (isset($this->shear[$player->getName()])) {
            unset($this->shear[$player->getName()]);
        }
        if (isset($this->axe[$player->getId()])) {
            unset($this->axe[$player->getId()]);
        }
        if (isset($this->inChest[$player->getId()])) {
            unset($this->inChest[$player->getId()]);
        }
        if (isset($this->pickaxe[$player->getId()])) {
            unset($this->pickaxe[$player->getId()]);
        }
        if (!$death) {
            $this->broadcastMessage("§7{$player->getDisplayName()} left");
        }

        if ($quitMsg != "") {
            $this->broadcastMessage("$quitMsg");
        }
    }

    public function spectator(Player $player)
    {
        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
                $index = "";
                foreach ($this->players as $i => $p) {
                    if ($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if ($index != "") {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }
        $api = Scoreboards::getInstance();
        $api->remove($player);
        $player->removeAllEffects();
        $this->ghost[$player->getName()] = $player;
        $player->setHealth(20);
        $player->setFood(20);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $eff = new EffectInstance(Effect::getEffect(Effect::BLINDNESS), 60, 2);
        $player->getInventory()->setHeldItemIndex(4);
        $eff->setVisible(false);
        $player->addEffect($eff);
        $spawnLoc = $this->level->getSafeSpawn();
        $spawnPos = new Vector3(
            round($spawnLoc->getX()) + 0.5,
            $spawnLoc->getY() + 10,
            round($spawnLoc->getZ()) + 0.5
        );
        $player->teleport($spawnPos);
        $player->addTitle("§l§cYou Died", "§r§eNow Spectating");
        $team = $this->getTeam($player);
        if ($this->phase == self::PHASE_GAME) {
            $count = 0;
            foreach ($this->players as $mate) {
                if ($this->getTeam($mate) == $team) {
                    $count++;
                }
            }
            if ($count <= 0) {
                $spawn = Vector3::fromString($this->data["treasure"][$team]);
                foreach ($this->level->getEntities() as $g) {
                    if ($g instanceof Generator) {
                        if ($g->asVector3()->distance($spawn) < 20) {
                            $g->close();
                        }
                    }
                }
                $color = [
                    "red" => "§cRed",
                    "blue" => "§9Blue",
                ];
                $this->broadcastMessage(
                    "§l§fTEAM ELIMINATED §r$color[$team] §fwas §celimiated!"
                );
            }
            if ($team == "red") {
                unset($this->redteam[$player->getName()]);
            }
            if ($team == "blue") {
                unset($this->blueteam[$player->getName()]);
            }
        }
        if (isset($this->invis[$player->getId()])) {
            $this->setInvis($player, false);
        }
        if (isset($this->broken[$player->getId()])) {
            unset($this->broken[$player->getId()]);
        }
        if (isset($this->finalkill[$player->getId()])) {
            unset($this->finalkill[$player->getId()]);
        }
        if (isset($this->redteam[$player->getName()])) {
            unset($this->redteam[$player->getName()]);
        }
        if (isset($this->blueteam[$player->getName()])) {
            unset($this->blueteam[$player->getName()]);
        }
        if (isset($this->armor[$player->getName()])) {
            unset($this->armor[$player->getName()]);
        }
        if (isset($this->shear[$player->getName()])) {
            unset($this->shear[$player->getName()]);
        }
        $player
            ->getInventory()
            ->setItem(
                8,
                Item::get(355, 14, 1)->setCustomName("§dBack to hub §7[use]")
            );
        $player
            ->getInventory()
            ->setItem(
                7,
                Item::get(Item::HEART_OF_THE_SEA, 0, 1)->setCustomName(
                    "§aNew game §7[use]"
                )
            );
        $this->setGhost($player, true);
        $this->plugin->addLose($player);
        $this->plugin->addDeath($player);
        $player
            ->getInventory()
            ->setItem(
                0,
                Item::get(Item::COMPASS, 0, 1)->setCustomName(
                    "§eTeleporter §7[use]"
                )
            );
        if (isset($this->players[$player->getName()])) {
            unset($this->players[$player->getName()]);
        }
        unset($this->kill[$player->getName()]);
        unset($this->index[$player->getName()]);
        if (isset($this->axe[$player->getId()])) {
            unset($this->axe[$player->getId()]);
        }
        if (isset($this->inChest[$player->getId()])) {
            unset($this->inChest[$player->getId()]);
        }
        if (isset($this->pickaxe[$player->getId()])) {
            unset($this->pickaxe[$player->getId()]);
        }
        $player->setScoreTag("");
    }

    public function respawn($player)
    {
        if (!($player instanceof Player)) {
            return;
        }
        $player->setGamemode($player::SURVIVAL);
        $player->sendTitle("§l§aRESPAWNED");
        $player->setHealth(20);
        $player->setFood(20);
        $original = $player->getInventory()->getItemInHand();
        $player->getInventory()->setItemInHand(Item::get(450,0,1));
        $player->broadcastEntityEvent(ActorEventPacket::CONSUME_TOTEM);
        $pk = new LevelEventPacket();
        $pk->evid = LevelEventPacket::EVENT_SOUND_TOTEM;
        $pk->position = $player->add(0, $player->eyeHeight, 0);
        $pk->data = 0;
        $player->dataPacket($pk);
        $player->getInventory()->setItemInHand($original);
        $index = $this->index[$player->getName()];
        $vc = Vector3::fromString($this->data["spawns"][$index]);
        $x = $vc->getX();
        $y = $vc->getY();
        $z = $vc->getZ();
        if($player instanceof Player){
            $player->teleport(new Vector3($x + 0.5, $y + 0.5, $z + 0.5));
        }
        $this->setArmor($player);
        $sword = Item::get(Item::WOODEN_SWORD, 0, 1);
        $this->setSword($player, $sword);
        $axe = $this->getAxeByTier($player, false);
        $pickaxe = $this->getPickaxeByTier($player, false);
        if (isset($this->axe[$player->getId()])) {
            if ($this->axe[$player->getId()] > 1) {
                $player->getInventory()->addItem($axe);
            }
        }
        if (isset($this->pickaxe[$player->getId()])) {
            if ($this->pickaxe[$player->getId()] > 1) {
                $player->getInventory()->addItem($pickaxe);
            }
        }
    }

    public function removeLobby()
    {
        $pos = Vector3::fromString($this->data["lobby"]);
        $x = $pos->getX();
        $y = $pos->getY();
        $z = $pos->getZ();
        $session = SessionHelper::createPluginSession(WE::getInstance());
        $selection = new Selection(
            $session->getUUID(),
            $this->level,
            $x - 15,
            $y - 3,
            $z - 15,
            $x + 15,
            $y + 10,
            $z + 15
        );
        $msg = [];
        $error = false;
        $blocks = API::blockParser("air", $msg, $error);
        API::fillAsync($selection, $session, $blocks, API::FLAG_BASE);
    }

    public function setSword($player, $sword)
    {
        if (!($player instanceof Player)) {
            return;
        }
        if (!$sword instanceof Sword) {
            return;
        }
        $team = $this->getTeam($player);
        $enchant = null;
        if (isset($this->teamsharpness[$team])) {
            if ($this->teamsharpness[$team] > 1) {
                $enchant = new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::SHARPNESS),
                    $this->teamsharpness[$team] - 1
                );
            }
        }
        if ($enchant !== null) {
            $sword->addEnchantment($enchant);
        }
        $sword->setUnbreakable(true);
        $player->getInventory()->setItem(0, $sword);
        if (isset($this->shear[$player->getName()])) {
            if (!$player->getInventory()->contains(Item::get(Item::SHEARS))) {
                $sh = Item::get(Item::SHEARS, 0, 1);
                $sh->setUnbreakable(true);
                $player->getInventory()->addItem($sh);
            }
        }
    }

    public function setArmor($player)
    {
        if (!($player instanceof Player)) {
            return;
        }
        $team = $this->getTeam($player);
        $enchant = null;
        if (isset($this->teamprotection[$team])) {
            if ($this->teamprotection[$team] > 1) {
                $enchant = new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::PROTECTION),
                    $this->teamprotection[$team] - 1
                );
            }
        }
        $color = null;
        if ($team == "red") {
            $color = new Color(255, 0, 0);
        }
        if ($team == "blue") {
            $color = new Color(0, 0, 255);
        }
        if ($color == null) {
            $color = new Color(0, 0, 0);
        }
        if (isset($this->armor[$player->getName()])) {
            $arm = $player->getArmorInventory();
            $armor = $this->armor[$player->getName()];
            if ($armor == "chainmail") {
                $helm = Item::get(Item::LEATHER_CAP, 0, 1);
                $helm->setCustomColor($color);
                $helm->setUnbreakable(true);
                if ($enchant !== null) {
                    $helm->addEnchantment($enchant);
                }
                $arm->setHelmet($helm);
                $chest = Item::get(Item::LEATHER_TUNIC, 0, 1);
                $chest->setCustomColor($color);
                if ($enchant !== null) {
                    $chest->addEnchantment($enchant);
                }
                $chest->setUnbreakable(true);
                $arm->setChestplate($chest);
                $leg = Item::get(Item::CHAINMAIL_LEGGINGS, 0, 1);
                if ($enchant !== null) {
                    $leg->addEnchantment($enchant);
                }
                $leg->setUnbreakable(true);
                $arm->setLeggings($leg);
                $boots = Item::get(Item::CHAINMAIL_BOOTS, 0, 1);
                $boots->setUnbreakable(true);
                if ($enchant !== null) {
                    $boots->addEnchantment($enchant);
                }
                $arm->setBoots($boots);
            }
            if ($armor == "iron") {
                $helm = Item::get(Item::LEATHER_CAP, 0, 1);
                $helm->setCustomColor($color);
                $helm->setUnbreakable(true);
                if ($enchant !== null) {
                    $helm->addEnchantment($enchant);
                }
                $arm->setHelmet($helm);
                $chest = Item::get(Item::LEATHER_TUNIC, 0, 1);
                $chest->setCustomColor($color);
                if ($enchant !== null) {
                    $chest->addEnchantment($enchant);
                }
                $chest->setUnbreakable(true);
                $arm->setChestplate($chest);
                $leg = Item::get(Item::IRON_LEGGINGS, 0, 1);
                if ($enchant !== null) {
                    $leg->addEnchantment($enchant);
                }
                $leg->setUnbreakable(true);
                $arm->setLeggings($leg);
                $boots = Item::get(Item::IRON_BOOTS, 0, 1);
                if ($enchant !== null) {
                    $boots->addEnchantment($enchant);
                }
                $boots->setUnbreakable(true);
                $arm->setBoots($boots);
            }
            if ($armor == "diamond") {
                $helm = Item::get(Item::LEATHER_CAP, 0, 1);
                $helm->setCustomColor($color);
                $helm->setUnbreakable(true);
                if ($enchant !== null) {
                    $helm->addEnchantment($enchant);
                }
                $arm->setHelmet($helm);
                $chest = Item::get(Item::LEATHER_TUNIC, 0, 1);
                $chest->setCustomColor($color);
                if ($enchant !== null) {
                    $chest->addEnchantment($enchant);
                }
                $chest->setUnbreakable(true);
                $arm->setChestplate($chest);
                $leg = Item::get(Item::DIAMOND_LEGGINGS, 0, 1);
                if ($enchant !== null) {
                    $leg->addEnchantment($enchant);
                }
                $leg->setUnbreakable(true);
                $arm->setLeggings($leg);
                $boots = Item::get(Item::DIAMOND_BOOTS, 0, 1);
                if ($enchant !== null) {
                    $boots->addEnchantment($enchant);
                }
                $boots->setUnbreakable(true);
                $arm->setBoots($boots);
            }
        } else {
            $arm = $player->getArmorInventory();
            $helm = Item::get(Item::LEATHER_CAP, 0, 1);
            $helm->setCustomColor($color);
            $helm->setUnbreakable(true);
            if ($enchant !== null) {
                $helm->addEnchantment($enchant);
            }
            $arm->setHelmet($helm);
            $chest = Item::get(Item::LEATHER_TUNIC, 0, 1);
            $chest->setCustomColor($color);
            if ($enchant !== null) {
                $chest->addEnchantment($enchant);
            }
            $chest->setUnbreakable(true);
            $arm->setChestplate($chest);
            $leg = Item::get(Item::LEATHER_PANTS, 0, 1);
            $leg->setCustomColor($color);
            if ($enchant !== null) {
                $leg->addEnchantment($enchant);
            }
            $leg->setUnbreakable(true);
            $arm->setLeggings($leg);
            $boots = Item::get(Item::LEATHER_BOOTS, 0, 1);
            $boots->setCustomColor($color);
            if ($enchant !== null) {
                $boots->addEnchantment($enchant);
            }
            $boots->setUnbreakable(true);
            $arm->setBoots($boots);
        }
    }

    public function startRespawn($player){
        if(!($player instanceof Player)) return; 
        $player->setGamemode($player::SPECTATOR);
        $player->removeAllEffects();
        $this->plugin->addDeath($player); 
        $player->sendTitle("§l§cYOU DIED");
        $this->respawnC[$player->getName()] = 6;
        $this->respawn[$player->getName()] = $player;
        $axe = $this->getLessTier($player, true);
        $pickaxe = $this->getLessTier($player, false);
        $this->axe[$player->getId()] = $axe;
        $this->pickaxe[$player->getId()] = $pickaxe;
        if($player instanceof Player){
            $pos = $this->level->getSafeSpawn();
            $x = $pos->getX();
            $y = $pos->getY();
            $z = $pos->getZ();
            $player->teleport(new Vector3(round($x) + 0.5, $y + 10, round($z) + 0.5));
            $eff = new EffectInstance(Effect::getEffect(Effect::BLINDNESS), 60, 2);
            $eff->setVisible(false);
            $player->addEffect($eff);
        }
    }

    public function setGhost(Player $player, bool $yes = true)
    {
        if ($yes) {
            $this->ghost[$player->getName()] = $player;
            $player->setGamemode(Player::SPECTATOR);
            foreach ($this->ghost as $p2) {
                $p2->showPlayer($player);
            }
        } else {
            $player->setGamemode($player::ADVENTURE);
            if (isset($this->ghost[$player->getName()])) {
                unset($this->ghost[$player->getName()]);
            }
        }
    }

    public function startGame() {
        $players = [];
        foreach ($this->players as $player) {
            $index = null;
            foreach ($this->players as $i => $p) {
                if($p->getId() == $player->getId()) {
                    $index = $i;
                }
            }
            $vc = Vector3::fromString($this->data["spawns"][$index]);
            $x = $vc->getX();
            $y = $vc->getY();
            $z = $vc->getZ();
            if($player instanceof Player){
                $player->teleport(new Vector3($x + 0.5, $y + 0.5, $z + 0.5));
            } 
            $this->setColorTag($player);
            $player->setNameTagVisible();
            $player->getInventory()->clearAll();
            $this->axe[$player->getId()] = 1;
            $this->pickaxe[$player->getId()] = 1;
            $player->setGamemode($player::SURVIVAL);
            $this->setArmor($player);
            $this->setSword($player, Item::get(Item::WOODEN_SWORD, 0, 1));
            $player->setImmobile(false);
            $this->addSound($player, 'mob.blaze.shoot', 1);
            $this->plugin->addPlayed($player);
            $players[$player->getName()] = $player;
        }
        $this->phase = 1;
        $this->players = $players; 
        $this->broadcastMessage("§l§cBedWars 1v1");
        $this->broadcastMessage("§l§eBridge to the middle or sides to access powerful upgrades");
        $this->broadcastMessage("Plugin By ShadowBonnie and dantedev No editing on scoreboard")
        $this->broadcastMessage("No cross-teaming! §r§bCross-teamers will be banned.");  
        $this->level->setTime(5000);
        $this->prepareWorld();
        $this->removeLobby();
    }

    public function prepareWorld()
    {
        foreach (["red", "blue"] as $teams) {
            $this->teamgenerator["$teams"] = 1;
            $this->teamsharpness["$teams"] = 1;
            $this->teamprotection["$teams"] = 1;
            $this->teamhaste["$teams"] = 1;
            $this->teamhealth["$teams"] = 1;
            $this->traps["$teams"] = 1;
        }
        foreach ($this->level->getTiles() as $tile) {
            if ($tile instanceof Furnace) {
                $nbt = Entity::createBaseNBT(
                    new Vector3($tile->x + 0.5, $tile->y + 1, $tile->z + 0.5),
                    null
                );
                $path = $this->plugin->getDataFolder() . "diamond.png";
                $skin = $this->plugin->getSkinFromFile($path);
                $nbt->setTag(
                    new CompoundTag("Skin", [
                        new StringTag("Data", $skin->getSkinData()),
                        new StringTag("Name", "Standard_CustomSlim"),
                        new StringTag("GeometryName", "geometry.player_head"),
                        new ByteArrayTag("GeometryData", Generator::GEOMETRY),
                    ])
                );
                $g = new Generator($tile->getLevel(), $nbt);
                $g->type = "gold";
                $g->Glevel = 1;
                $g->setScale(0.000001);
                $g->spawnToAll();
            }
            if ($tile instanceof EnchantTable) {
                $nbt = Entity::createBaseNBT(
                    new Vector3($tile->x + 0.5, $tile->y + 5, $tile->z + 0.5),
                    null
                );
                $path = $this->plugin->getDataFolder() . "diamond.png";
                $skin = $this->plugin->getSkinFromFile($path);
                $nbt->setTag(
                    new CompoundTag("Skin", [
                        new StringTag("Data", $skin->getSkinData()),
                        new StringTag("Name", "Standard_CustomSlim"),
                        new StringTag("GeometryName", "geometry.player_head"),
                        new ByteArrayTag("GeometryData", Generator::GEOMETRY),
                    ])
                );
                $g = new Generator($tile->getLevel(), $nbt);
                $g->type = "diamond";
                $g->Glevel = 1;
                $g->setScale(1.5);
                $g->spawnToAll();
            }
            if ($tile instanceof Skull) {
                $nbt = Entity::createBaseNBT(
                    new Vector3($tile->x + 0.5, $tile->y + 5, $tile->z + 0.5),
                    null
                );
                $path = $this->plugin->getDataFolder() . "emerald.png";
                $skin = $this->plugin->getSkinFromFile($path);
                $nbt->setTag(
                    new CompoundTag("Skin", [
                        new StringTag("Data", $skin->getSkinData()),
                        new StringTag("Name", "Standard_CustomSlim"),
                        new StringTag("GeometryName", "geometry.player_head"),
                        new ByteArrayTag("GeometryData", Generator::GEOMETRY),
                    ])
                );
                $g = new Generator($tile->getLevel(), $nbt);
                $g->type = "emerald";
                $g->Glevel = 1;
                $g->setScale(1.5);
                $g->spawnToAll();
            }
        }
        $this->checkTeam();
    }

    public function upgradeGeneratorTier($type, $level)
    {
        if ($type == "diamond") {
            foreach ($this->level->getEntities() as $e) {
                if ($e instanceof Generator) {
                    if ($e->type == "diamond") {
                        $e->Glevel = $level;
                    }
                }
            }
        }
        if ($type == "emerald") {
            foreach ($this->level->getEntities() as $e) {
                if ($e instanceof Generator) {
                    if ($e->type == "emerald") {
                        $e->Glevel = $level;
                    }
                }
            }
        }
    }

    public function destroyAllBeds()
    {
        foreach (["red", "blue", "yellow", "green"] as $t) {
            $pos = Vector3::fromString($this->data["treasure"][$t]);
            if (
                ($bed = $this->level->getBlockAt(
                    $pos->x,
                    $pos->y,
                    $pos->z
                )) instanceof Bed
            ) {
                $next = $bed->getOtherHalf();
                $this->level->setBlock($bed, Block::get(0));
                $this->level->setBlock($next, Block::get(0));
                if ($t == "red") {
                    foreach ($this->redteam as $red) {
                        if ($red instanceof Player) {
                            $red->addTitle(
                                "§l§fBED DESTRUCTION > ",
                                "§r§fyou will no longer respawn"
                            );
                            $this->addSound($red, "mob.wither.death", 1);
                        }
                    }
                }
                if ($t == "blue") {
                    foreach ($this->blueteam as $blue) {
                        if ($blue instanceof Player) {
                            $blue->addTitle(
                                "§l§fBED DESTRUCTION > ",
                                "§r§fyou will no longer respawn"
                            );
                            $this->addSound($blue, "mob.wither.death", 1);
                        }
                    }
                }
                if ($t == "yellow") {
                    foreach ($this->yellowteam as $yellow) {
                        if ($yellow instanceof Player) {
                            $yellow->addTitle(
                                "§l§fBED DESTRUCTION > ",
                                "§r§fyou will no longer respawn"
                            );
                            $this->addSound($yellow, "mob.wither.death", 1);
                        }
                    }
                }
                if ($t == "green") {
                    foreach ($this->greenteam as $green) {
                        if ($green instanceof Player) {
                            $green->addTitle(
                                "§l§fBED DESTRUCTION > ",
                                "§r§fyou will no longer respawn"
                            );
                            $this->addSound($green, "mob.wither.death", 1);
                        }
                    }
                }
            }
        }
    }

    public function checkTeam()
    {
        if (count($this->redteam) <= 0) {
            $pos = Vector3::fromString($this->data["treasure"]["red"]);
            if (
                ($bed = $this->level->getBlockAt(
                    $pos->x,
                    $pos->y,
                    $pos->z
                )) instanceof Bed
            ) {
                $next = $bed->getOtherHalf();
                $this->level->setBlock($bed, Block::get(0));
                $this->level->setBlock($next, Block::get(0));
            }
            foreach ($this->level->getEntities() as $g) {
                if ($g instanceof Generator) {
                    if ($g->asVector3()->distance($pos) < 20) {
                        $g->close();
                    }
                }
            }
        }
        if (count($this->blueteam) <= 0) {
            $pos = Vector3::fromString($this->data["treasure"]["blue"]);
            if (
                ($bed = $this->level->getBlockAt(
                    $pos->x,
                    $pos->y,
                    $pos->z
                )) instanceof Bed
            ) {
                $next = $bed->getOtherHalf();
                $this->level->setBlock($bed, Block::get(0));
                $this->level->setBlock($next, Block::get(0));
            }
            foreach ($this->level->getEntities() as $g) {
                if ($g instanceof Generator) {
                    if ($g->asVector3()->distance($pos) < 20) {
                        $g->close();
                    }
                }
            }
        }
        if (count($this->yellowteam) <= 0) {
            $pos = Vector3::fromString($this->data["treasure"]["yellow"]);
            if (
                ($bed = $this->level->getBlockAt(
                    $pos->x,
                    $pos->y,
                    $pos->z
                )) instanceof Bed
            ) {
                $next = $bed->getOtherHalf();
                $this->level->setBlock($bed, Block::get(0));
                $this->level->setBlock($next, Block::get(0));
            }
            foreach ($this->level->getEntities() as $g) {
                if ($g instanceof Generator) {
                    if ($g->asVector3()->distance($pos) < 20) {
                        $g->close();
                    }
                }
            }
        }
        if (count($this->greenteam) <= 0) {
            $pos = Vector3::fromString($this->data["treasure"]["green"]);
            if (
                ($bed = $this->level->getBlockAt(
                    $pos->x,
                    $pos->y,
                    $pos->z
                )) instanceof Bed
            ) {
                $next = $bed->getOtherHalf();
                $this->level->setBlock($bed, Block::get(0));
                $this->level->setBlock($next, Block::get(0));
            }
            foreach ($this->level->getEntities() as $g) {
                if ($g instanceof Generator) {
                    if ($g->asVector3()->distance($pos) < 20) {
                        $g->close();
                    }
                }
            }
        }
    }

    public function destroyTreasure($team, $player = null)
    {
        if (!isset($this->data["treasure"][$team])) {
            return;
        }
        $pos = Vector3::fromString($this->data["treasure"][$team]);
        if (
            ($bed = $this->level->getBlockAt(
                $pos->x,
                $pos->y,
                $pos->z
            )) instanceof Bed
        ) {
            $next = $bed->getOtherHalf();
            $this->level->addParticle(new DestroyBlockParticle($bed, $bed));
            $this->level->addParticle(new DestroyBlockParticle($next, $bed));
            $this->level->setBlock($bed, Block::get(0));
            $this->level->setBlock($next, Block::get(0));
        }
        $c = null;
        if ($team == "red") {
            $c = "§c";
        }
        if ($team == "blue") {
            $c = "§9";
        }
        if ($team == "yellow") {
            $c = "§e";
        }
        if ($team == "green") {
            $c = "§a";
        }
        $tn = ucwords($team);
        if ($player instanceof Player) {
            $this->broadcastMessage(
                "§l§fBED DESTRUCTION > §r{$c}{$tn} team §fbed was disminated §r§fby {$player->getDisplayName()}"
            );
            if (isset($this->broken[$player->getId()])) {
                $this->broken[$player->getId()]++;
            }
        }
        foreach ($this->players as $p) {
            if ($p instanceof Player && $this->getTeam($p) == $team) {
                $p->sendTitle(
                    "§l§fBED DESTRUCTION > ",
                    "§r§fyou will no longer respawn"
                );
                $this->addSound($p, "mob.wither.death", 1);
            }
        }
    }

    public function Wins(string $team)
    {
        foreach ($this->level->getEntities() as $g) {
            if ($g instanceof Generator) {
                $g->close();
            }
            if ($g instanceof Golem) {
                $g->close();
            }
            if ($g instanceof TNT) {
                $g->close();
            }
            if ($g instanceof Bedbug) {
                $g->close();
            }
            if ($g instanceof Fireball) {
                $g->close();
            }
            if ($g instanceof ItemEntity) {
                $g->close();
            }
        }
        foreach ($this->level->getPlayers() as $p) {
            $p->setDisplayName($p->getName());
            $p->setScoreTag("");
            if (isset($this->ghost[$p->getName()])) {
                $p->setGamemode($p::SURVIVAL);
                $p->getInventory()->removeItem(item::get(Item::COMPASS));
            }
        }
        foreach ($this->players as $player) {
            if ($this->getTeam($player) == $team) {
                $player->setHealth(20);
                $player->setFood(20);
                $player->getCursorInventory()->clearAll();
                $player->sendTitle("§l§6VICTORY");
                $player->setDisplayName($player->getName());
                $player->setScoreTag("");
                $player->getArmorInventory()->clearAll();
                $player->getCursorInventory()->clearAll();
                $this->addSound($player, "random.levelup", 1);
                $this->plugin->addWin($player);
                $this->plugin->addRewardWIn($player);
                $this->plugin->showTopFinalKill($player);
                $api = Scoreboards::getInstance();
                $api->remove($player);
                $player->setDisplayName($player->getName());
                $player->setScoreTag("");
                $player
                    ->getInventory()
                    ->setItem(
                        8,
                        Item::get(355, 14, 1)->setCustomName(
                            "§dBack to hub §7[use]"
                        )
                    );
                $player
                    ->getInventory()
                    ->setItem(
                        7,
                        Item::get(Item::HEART_OF_THE_SEA, 0, 1)->setCustomName(
                            "§aNew game §7[use]"
                        )
                    );
            }
        }
        $this->placedBlock = [];
        $this->teamgenerator = [];
        $this->teamhaste = [];
        $this->teamprotection = [];
        $this->teamsharpness = [];
        $this->teamhealth = [];
        $this->traps = [];
        $this->axe = [];
        $this->pickaxe = [];
        $this->milk = [];
        $this->inChest = [];
        $this->broadcastMessage("§cGame OVER!");
        $teamName = [
            "red" => "§r§cRed Team",
            "blue" => "§r§9Blue Team",
            "green" => "§r§aGreen Team",
            "yellow" => "§r§eYellow Team",
        ];
        $this->broadcastMessage(
            "§l§fTEAM WINNER $teamName[$team] are the WINNERS!"
        );
        $this->phase = self::PHASE_RESTART;
    }

    public function draw()
    {
        foreach ($this->level->getEntities() as $g) {
            if ($g instanceof Generator) {
                $g->close();
            }
            if ($g instanceof Golem) {
                $g->close();
            }
            if ($g instanceof TNT) {
                $g->close();
            }
            if ($g instanceof Bedbug) {
                $g->close();
            }
            if ($g instanceof Fireball) {
                $g->close();
            }
            if ($g instanceof ItemEntity) {
                $g->close();
            }
        }
        foreach ($this->level->getPlayers() as $p) {
            $p->setDisplayName($p->getName());
            $p->setScoreTag("");
            $this->addSound($p, "mob.guardian.death", 1);
            if (isset($this->ghost[$p->getName()])) {
                $p->setGamemode($p::SURVIVAL);
                $p->getInventory()->removeItem(item::get(Item::COMPASS));
                $this->setGhost($p, false);
            }
        }
        foreach ($this->players as $player) {
            if (
                $player === null ||
                !$player instanceof Player ||
                !$player->isOnline()
            ) {
                $this->phase = self::PHASE_RESTART;
                return;
            }
            $player->setHealth(20);
            $player->setFood(20);
            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $api = Scoreboards::getInstance();
            $api->remove($player);
            $player
                ->getInventory()
                ->setItem(
                    8,
                    Item::get(355, 14, 1)->setCustomName("§dBack to hub §7[use]")
                );
            $player
                ->getInventory()
                ->setItem(
                    7,
                    Item::get(Item::HEART_OF_THE_SEA, 0, 1)->setCustomName(
                        "§aNew game §7[use]"
                    )
                );
        }
        $this->placedBlock = [];
        $this->teamgenerator = [];
        $this->teamhaste = [];
        $this->teamprotection = [];
        $this->teamsharpness = [];
        $this->teamhealth = [];
        $this->traps = [];
        $this->axe = [];
        $this->pickaxe = [];
        $this->milk = [];
        $this->inChest = [];
        $this->broadcastMessage("§cGAME OVER", self::MSG_TITLE);
        $this->phase = self::PHASE_RESTART;
    }

    /**
     * @param Player $player
     * @return bool $isInGame
     */
    public function inGame($player): bool
    {
        if (!$player instanceof Player) {
            return false;
        }
        if ($this->phase == self::PHASE_LOBBY) {
            $inGame = false;
            foreach ($this->players as $players) {
                if ($players->getId() == $player->getId()) {
                    $inGame = true;
                }
            }
            return $inGame;
        } else {
            return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     */
    public function broadcastMessage(
        string $message,
        int $id = 0,
        string $subMessage = ""
    ) {
        foreach ($this->level->getPlayers() as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->addTitle($message, $subMessage);
                    break;
            }
        }
    }

    public function hitEntity(ProjectileHitEntityEvent $event)
    {
        $pro = $event->getEntity();
        $hitEntity = $event->getEntityHit();
        $owner = $pro->getOwningEntity();
        if ($pro instanceof \pocketmine\entity\projectile\Arrow) {
            if ($owner instanceof Player && $hitEntity instanceof Player) {
                if ($this->inGame($owner)) {
                    $owner->sendMessage(
                        "§f{$hitEntity->getDisplayName()} is now {$hitEntity->getHealth()} hearts"
                    );
                    $this->addSound($owner, "random.orb", 0.5);
                }
            }
        } else {
            if ($owner instanceof Player && $hitEntity instanceof Player) {
                if ($this->inGame($owner)) {
                    $this->addSound($hitEntity, "step.snow", 1);
                    $this->addSound($owner, "random.orb", 0.5);
                }
            }
        }
    }

    public function clearItem()
    {
        foreach ($this->level->getEntities() as $item) {
            if ($item instanceof ItemEntity) {
                $originalItem = $item->getItem();
                $item->close();
            }
        }
    }

    public function onItemSpawn(\pocketmine\event\entity\ItemSpawnEvent $event)
    {
        $entity = $event->getEntity();
        if ($entity->level->getFolderName() !== $this->level->getFolderName()) {
            return;
        }
        $entities = $entity
            ->getLevel()
            ->getNearbyEntities(
                $entity->getBoundingBox()->expandedCopy(1, 1, 1)
            );
        if (empty($entities)) {
            return;
        }
        if ($entity instanceof ItemEntity) {
            $originalItem = $entity->getItem();
            $i = 0;
            foreach ($entities as $e) {
                if (
                    $e instanceof ItemEntity and
                    $entity->getId() !== $e->getId()
                ) {
                    $item = $e->getItem();
                    if (
                        in_array($originalItem->getId(), [
                            Item::DIAMOND,
                            Item::EMERALD,
                        ])
                    ) {
                        if ($item->getId() === $originalItem->getId()) {
                            $e->flagForDespawn();
                            $entity
                                ->getItem()
                                ->setCount(
                                    $originalItem->getCount() +
                                        $item->getCount()
                                );
                        }
                    }
                }
            }
        }
    }

    public function onCraftItem(CraftItemEvent $event)
    {
        $player = $event->getPlayer();
        if ($player instanceof Player) {
            if ($this->inGame($player)) {
                $event->setCancelled();
            }
        }
    }

    public function onConsume(
        \pocketmine\event\player\PlayerItemConsumeEvent $event
    ) {
        $player = $event->getPlayer();
        $item = $event->getItem();
        $itemHand = $player->getInventory()->getItemInHand();
        $in = $item->getCustomName();
        if ($this->inGame($player)) {
            if ($item->getId() == 373 && $item->getDamage() == 16) {
                $event->setCancelled();
                $player->getInventory()->setItemInHand(Item::get(0));
                $eff = new EffectInstance(
                    Effect::getEffect(Effect::SPEED),
                    900,
                    1
                );
                $eff->setVisible(true);
                $player->addEffect($eff);
            }
            if ($item->getId() == 373 && $item->getDamage() == 11) {
                $event->setCancelled();
                $player->getInventory()->setItemInHand(Item::get(0));
                $eff = new EffectInstance(
                    Effect::getEffect(Effect::JUMP_BOOST),
                    900,
                    3
                );
                $eff->setVisible(true);
                $player->addEffect($eff);
            }
            if ($item->getId() == 373 && $item->getDamage() == 7) {
                $event->setCancelled();
                $player->getInventory()->setItemInHand(Item::get(0));
                $eff = new EffectInstance(
                    Effect::getEffect(Effect::INVISIBILITY),
                    600,
                    1
                );
                $eff->setVisible(true);
                $player->addEffect($eff);
                $this->setInvis($player, true);
            }
            if ($item->getId() == Item::BUCKET && $item->getDamage() == 1) {
                $event->setCancelled();
                $player->getInventory()->setItemInHand(Item::get(0));
                $this->milk[$player->getId()] = 30;
                $player->sendMessage(
                    "§aTrap wont be affected on §c30 §bseconds"
                );
            }
        }
    }

    public function setInvis($player, $value)
    {
        $arm = $player->getArmorInventory();
        if ($value) {
            $this->invis[$player->getId()] = $player;
            $hide = $this->armorInvis($player, true);
            foreach ($this->players as $p) {
                if ($player->getId() == $p->getId()) {
                    $pk2 = new InventoryContentPacket();
                    $pk2->windowId = $player->getWindowId($arm);
                    $pk2->items = array_map(
                        [ItemStackWrapper::class, "legacy"],
                        $arm->getContents(true)
                    );
                    $player->dataPacket($pk2);
                } else {
                    if ($this->getTeam($player) !== $this->getTeam($p)) {
                        $p->dataPacket($hide);
                    }
                }
            }
        } else {
            if (isset($this->invis[$player->getId()])) {
                unset($this->invis[$player->getId()]);
            }
            $player->setInvisible(false);
            $nohide = $this->armorInvis($player, false);
            foreach ($this->players as $p) {
                if ($player->getId() == $p->getId()) {
                    $pk2 = new InventoryContentPacket();
                    $pk2->windowId = $player->getWindowId($arm);
                    $pk2->items = array_map(
                        [ItemStackWrapper::class, "legacy"],
                        $arm->getContents(true)
                    );
                    $player->dataPacket($pk2);
                } else {
                    if ($this->getTeam($player) !== $this->getTeam($p)) {
                        $p->dataPacket($nohide);
                    }
                }
            }
        }
    }

    public function armorInvis($player, bool $hide = true)
    {
        if ($hide) {
            $pk = new MobArmorEquipmentPacket();
            $pk->entityRuntimeId = $player->getId();
            $pk->head = Item::get(0);
            $pk->chest = Item::get(0);
            $pk->legs = Item::get(0);
            $pk->feet = Item::get(0);
            $pk->encode();
            return $pk;
        } else {
            $arm = $player->getArmorInventory();
            $pk = new MobArmorEquipmentPacket();
            $pk->entityRuntimeId = $player->getId();
            $pk->head = $arm->getHelmet();
            $pk->chest = $arm->getChestplate();
            $pk->legs = $arm->getLeggings();
            $pk->feet = $arm->getBoots();
            $pk->encode();
            return $pk;
        }
    }

    public function onExplode(EntityExplodeEvent $event)
    {
        $tnt = $event->getEntity();
        if (
            $tnt->getLevel()->getFolderName() !== $this->level->getFolderName()
        ) {
            return;
        }
        if ($tnt instanceof TNT || $tnt instanceof Fireball) {
            $newList = [];
            foreach ($event->getBlockList() as $block) {
                $pos = new Vector3(
                    round($block->x) + 0.5,
                    $block->y,
                    round($block->z) + 0.5
                );
                if (
                    $block->getId() !== Block::OBSIDIAN &&
                    $block->getId() !== 241
                ) {
                    if (in_array($pos->__toString(), $this->placedBlock)) {
                        $newList[] = $block;
                    }
                }
            }
            $event->setBlockList($newList);
        }
    }

    public function blockUpdateEvent(BlockUpdateEvent $event)
    {
        $block = $event->getBlock();
        if (
            $block->getLevel()->getFolderName() == $this->level->getFolderName()
        ) {
            $event->setCancelled();
        }
    }

    public function leavesDecayEvent(LeavesDecayEvent $event)
    {
        $event->setCancelled();
    }

    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event)
    {
        $player = $event->getPlayer();
        if (isset($this->ghost[$player->getName()])) {
            if ($player->getY() < -5) {
                $player->teleport($this->level->getSafeSpawn());
            }
        }
        if ($this->inGame($player)) {
            if ($this->phase == self::PHASE_LOBBY) {
                $lv = Vector3::fromString($this->data["lobby"]);
                $p = $lv->getY() - 3;
                if ($player->getY() < $p) {
                    $player->teleport(
                        Vector3::fromString($this->data["lobby"])
                    );
                }
            }
            if ($this->phase == self::PHASE_GAME) {
                if (isset($this->milk[$player->getId()])) {
                    return;
                }
                foreach (["red", "blue", "yellow", "green"] as $teams) {
                    $pos = Vector3::fromString($this->data["treasure"][$teams]);
                    if ($player->distance($pos) < 8) {
                        if ($this->getTeam($player) !== $teams) {
                            if (isset($this->itstrap[$teams])) {
                                $this->traps[$teams]--;
                                unset($this->itstrap[$teams]);
                                $eff = new EffectInstance(
                                    Effect::getEffect(Effect::BLINDNESS),
                                    160,
                                    0
                                );
                                $eff->setVisible(true);
                                $player->addEffect($eff);
                                $eff = new EffectInstance(
                                    Effect::getEffect(Effect::SLOWNESS),
                                    160,
                                    1
                                );
                                $eff->setVisible(true);
                                $player->addEffect($eff);
                                foreach ($this->players as $p) {
                                    if ($this->getTeam($p) == $teams) {
                                        $p->sendTitle("§l§cTRAP TRIGGERED", "");
                                    }
                                }
                            }
                            if (isset($this->minertrap[$teams])) {
                                $this->traps[$teams]--;
                                unset($this->minertrap[$teams]);
                                $eff = new EffectInstance(
                                    Effect::getEffect(Effect::FATIGUE),
                                    160,
                                    0
                                );
                                $eff->setVisible(true);
                                $player->addEffect($eff);
                                foreach ($this->players as $p) {
                                    if ($this->getTeam($p) == $teams) {
                                        $p->sendTitle("§l§cTRAP TRIGGERED", "");
                                    }
                                }
                            }
                            if (isset($this->alarmtrap[$teams])) {
                                $this->traps[$teams]--;
                                unset($this->alarmtrap[$teams]);
                                foreach ($this->players as $p) {
                                    if ($this->getTeam($p) == $teams) {
                                        $p->sendTitle("§l§cTRAP TRIGGERED", "");
                                    }
                                }
                            }
                            if (isset($this->countertrap[$teams])) {
                                $this->traps[$teams]--;
                                unset($this->countertrap[$teams]);
                                foreach ($this->players as $p) {
                                    if ($this->getTeam($p) == $teams) {
                                        $p->sendTitle("§l§cTRAP TRIGGERED", "");
                                        $eff = new EffectInstance(
                                            Effect::getEffect(Effect::SPEED),
                                            300,
                                            0
                                        );
                                        $eff->setVisible(true);
                                        $p->addEffect($eff);
                                        $eff = new EffectInstance(
                                            Effect::getEffect(
                                                Effect::JUMP_BOOST
                                            ),
                                            300,
                                            1
                                        );
                                        $eff->setVisible(true);
                                        $p->addEffect($eff);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function onCmd(PlayerCommandPreprocessEvent $ev)
    {
        if ($ev->isCancelled()) {
            return;
        }
        $pl = $ev->getPlayer();
        $cmdline = trim($ev->getMessage());
        if ($cmdline == "") {
            return;
        }
        $cmdline = preg_split("/\s+/", $cmdline);
        $cmd = strtolower($cmdline[0]);
        if ($this->inGame($pl) && $this->phase == self::PHASE_GAME) {
            if (in_array($cmd, ["/give"])) {
                $ev->setCancelled();
            }
        }
    }

    public function projectileOnHit(
        \pocketmine\event\entity\ProjectileHitEvent $event
    ) {
        $pro = $event->getEntity();
        $player = $pro->getOwningEntity();
        if ($player instanceof Player) {
            if ($this->inGame($player)) {
                if ($pro instanceof \pocketmine\entity\projectile\Snowball) {
                    $this->spawnBedbug(
                        $pro->asVector3(),
                        $player->getLevel(),
                        $player
                    );
                }
            }
        }
    }

    public function onChat(PlayerChatEvent $event)
    {
        $player = $event->getPlayer();
        $msg = $event->getMessage();
        if ($event->isCancelled()) {
            return;
        }
        if (!$this->inGame($player)) {
            return;
        }
        if ($this->phase == self::PHASE_GAME) {
            $f = $msg[0];
            if ($f == "!") {
                $msg = str_replace("!", "", $msg);
                if (trim($msg) !== "") {
                    $this->broadcastMessage(
                        "§6SHOUT §r{$player->getDisplayName()}: §f{$msg}"
                    );
                }
            } else {
                $team = $this->getTeam($player);
                foreach ($this->players as $pt) {
                    if ($this->getTeam($pt) == $team) {
                        $pt->sendMessage(
                            "§aTEAM §r{$player->getDisplayName()}: §f{$msg}"
                        );
                    }
                }
            }
            $event->setCancelled();
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onPickItem(InventoryPickupItemEvent $event)
    {
        $inv = $event->getInventory();
        $player = $inv->getHolder();
        if ($event->isCancelled()) {
            return;
        }
        if (
            $player instanceof Player &&
            $player->getLevel()->getFolderName() ==
                $this->level->getFolderName()
        ) {
            if ($this->phase == self::PHASE_RESTART) {
                $event->setCancelled();
            }
        }
    }

    public function onExhaust(PlayerExhaustEvent $event)
    {
        $player = $event->getPlayer();
        if (
            $this->phase == self::PHASE_LOBBY ||
            $this->phase == self::PHASE_RESTART
        ) {
            if ($this->inGame($player)) {
                $event->setCancelled();
            }
        }
    }

    public function onRegen(EntityRegainHealthEvent $event)
    {
        $player = $event->getEntity();
        if ($event->isCancelled()) {
            return;
        }
        if ($player instanceof Player) {
            if ($event->getRegainReason() == $event::CAUSE_SATURATION) {
                $event->setAmount(0.001);
            }
        }
    }

    public function onTrans(InventoryTransactionEvent $event)
    {
        $transaction = $event->getTransaction();
        if ($this->phase !== self::PHASE_GAME) {
            return;
        }
        foreach ($transaction->getActions() as $action) {
            $item = $action->getSourceItem();
            $source = $transaction->getSource();
            if ($source instanceof Player) {
                if ($this->inGame($source)) {
                    if ($action instanceof SlotChangeAction) {
                        if (
                            $action->getInventory() instanceof PlayerInventory
                        ) {
                            if ($item instanceof Sword) {
                                $event->setCancelled();
                            }
                        }
                        if (
                            $action->getInventory() instanceof
                            \pocketmine\inventory\ArmorInventory
                        ) {
                            if ($item instanceof Armor) {
                                $event->setCancelled();
                            }
                        }
                        if (
                            isset($this->inChest[$source->getId()]) &&
                            $action->getInventory() instanceof PlayerInventory
                        ) {
                            if (
                                $item instanceof Pickaxe ||
                                $item instanceof Axe
                            ) {
                                $event->setCancelled();
                            }
                        }
                    }
                }
            }
        }
    }

    public function onOpenInventory(InventoryOpenEvent $event)
    {
        $player = $event->getPlayer();
        $inv = $event->getInventory();
        if ($this->inGame($player)) {
            if ($this->phase == self::PHASE_GAME) {
                if (
                    $inv instanceof ChestInventory ||
                    $inv instanceof EnderChestInventory
                ) {
                    $this->inChest[$player->getId()] = $player;
                }
            }
        }
    }

    public function onCloseInventory(InventoryCloseEvent $event)
    {
        $player = $event->getPlayer();
        $inv = $event->getInventory();
        if ($this->inGame($player)) {
            if ($this->phase == self::PHASE_GAME) {
                if (
                    $inv instanceof ChestInventory ||
                    $inv instanceof EnderChestInventory
                ) {
                    if (isset($this->inChest[$player->getId()])) {
                        unset($this->inChest[$player->getId()]);
                    }
                }
            }
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onBreak(BlockBreakEvent $event)
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();
        if ($this->inGame($player)) {
            $event->setXpDropAmount(0);
            $pos = new Vector3(
                floor($block->x) + 0.5,
                $block->y,
                floor($block->z) + 0.5
            );
            if (!in_array($pos->__toString(), $this->placedBlock)) {
                $event->setCancelled();
            }
            if ($block instanceof Bed) {
                $next = $block->getOtherHalf();
                $red = $this->data["treasure"]["red"];
                $blue = $this->data["treasure"]["blue"];
                $yellow = $this->data["treasure"]["yellow"];
                $green = $this->data["treasure"]["green"];
                if (
                    in_array(
                        $pos = (new Vector3(
                            $next->x,
                            $next->y,
                            $next->z
                        ))->__toString(),
                        [$red, $blue, $yellow, $green]
                    )
                ) {
                    $team = null;
                    if ($pos == $red) {
                        $team = "red";
                    }
                    if ($pos == $blue) {
                        $team = "blue";
                    }
                    if ($pos == $yellow) {
                        $team = "yellow";
                    }
                    if ($pos == $green) {
                        $team = "green";
                    }
                    if (
                        $this->getTeam($player) !== $team &&
                        !$player->isSpectator()
                    ) {
                        $this->destroyTreasure($team, $player);
                        $this->plugin->addBroken($player);
                        $this->plugin->addRewardBed($player);
                    }
                    return;
                }
                $red = $this->data["treasure"]["red"];
                $blue = $this->data["treasure"]["blue"];
                $yellow = $this->data["treasure"]["yellow"];
                $green = $this->data["treasure"]["green"];
                if (
                    in_array(
                        $pos = (new Vector3(
                            $block->x,
                            $block->y,
                            $block->z
                        ))->__toString(),
                        [$red, $blue, $yellow, $green]
                    )
                ) {
                    $team = null;
                    if ($pos == $red) {
                        $team = "red";
                    }
                    if ($pos == $blue) {
                        $team = "blue";
                    }
                    if ($pos == $yellow) {
                        $team = "yellow";
                    }
                    if ($pos == $green) {
                        $team = "green";
                    }
                    if (
                        $this->getTeam($player) !== $team &&
                        !$player->isSpectator()
                    ) {
                        $this->destroyTreasure($team, $player);
                        $this->plugin->addBroken($player);
                        $this->plugin->addRewardBed($player);
                    }
                }
            }
        }
    }

    public function onPlace(BlockPlaceEvent $event)
    {
        $item = $event->getItem();
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $itemN = $item->getCustomName();
        $ih = $player->getInventory()->getItemInHand();
        if ($this->inGame($player)) {
            $spawnY = $this->data["maxY"] + 20;
            if ($block->getY() > $spawnY) {
                $event->setCancelled();
                $rand = rand(1, 3);
                switch ($rand) {
                    case 1:
                        $player->sendMessage(
                            "§ccant place block outside play region"
                        );
                        $this->addSound($player, "note.bass", 1);
                        break;
                    default:
                        break;
                }
            }
            $entities = $block
                ->getLevel()
                ->getNearbyEntities(
                    $block->getBoundingBox()->expandedCopy(1, 2, 1)
                );
            $i = 0;
            foreach ($entities as $e) {
                if ($e instanceof Generator) {
                    $i++;
                }
            }
            if ($i > 0) {
                $event->setCancelled();
            }
        }
        if ($this->inGame($player)) {
            $index = $this->index[$player->getName()];
            if ($event->isCancelled()) {
                return;
            }
            foreach ($this->data["spawns"] as $spawn) {
                $lv = Vector3::fromString($spawn);
                if ($block->asVector3()->distance($lv) < 6) {
                    $event->setCancelled();
                } else {
                    $pos = new Vector3(
                        floor($block->x) + 0.5,
                        $block->y,
                        floor($block->z) + 0.5
                    );
                    $this->placedBlock[] = $pos->__toString();
                }
            }
            if ($block->getId() == Block::TNT) {
                $event->setCancelled();
                $pos = new Vector3(
                    floor($block->getX()),
                    floor($block->getY()),
                    floor($block->getZ())
                );
                $this->spawnTNT($pos, $player->getLevel(), $player, 4);
                $ih->setCount($ih->getCount() - 1);
                $player->getInventory()->setItemInHand($ih);
            }
            if ($block->getId() == Block::FLOWING_WATER) {
                $ih->setCount($ih->getCount() - 1);
                $player->getInventory()->setItemInHand($ih);
            }
        }
    }

    public function spawnTNT($pos, $level, $player, $type)
    {
        $mot = (new Random())->nextSignedFloat() * M_PI * 2;
        $nbt = Entity::createBaseNBT(
            $pos->add(0.5, 0, 0.5),
            new Vector3(-sin($mot) * 0.02, 0.2, -cos($mot) * 0.02)
        );
        $tnt = new TNT($level, $nbt);
        $tnt->type = $type;
        $tnt->owner = $player->getName();
        $tnt->spawnToAll();
    }

    public function spawnGolem($pos, $level, $player)
    {
        if ($this->phase !== self::PHASE_GAME) {
            return;
        }
        $nbt = Entity::createBaseNBT($pos);
        $entity = new Golem($level, $nbt);
        $entity->arena = $this;
        $entity->owner = $player;
        $entity->spawnToAll();
    }

    public function spawnBedbug($pos, $level, $player)
    {
        if ($this->phase !== self::PHASE_GAME) {
            return;
        }
        $nbt = Entity::createBaseNBT($pos);
        $entity = new Bedbug($level, $nbt);
        $entity->arena = $this;
        $entity->owner = $player;
        $entity->spawnToAll();
    }

    public function spawnFireball($pos, $level, $player)
    {
        $nbt = Entity::createBaseNBT(
            $pos,
            $player->getDirectionVector(),
            ($player->yaw > 180 ? 360 : 0) - $player->yaw,
            -$player->pitch
        );
        $entity = new Fireball($level, $nbt, $player);
        $entity->setMotion(
            $player
                ->getDirectionVector()
                ->normalize()
                ->multiply(0.5)
        );
        $entity->spawnToAll();
    }

    public function onDrop(PlayerDropItemEvent $event)
    {
        $player = $event->getPlayer();
        $item = $event->getItem();
        if (
            $player->getLevel()->getFolderName() ==
            $this->level->getFolderName()
        ) {
            if (
                $this->phase == self::PHASE_LOBBY ||
                $this->phase == self::PHASE_RESTART
            ) {
                $event->setCancelled();
            }
            if ($this->phase == self::PHASE_GAME) {
                if (
                    $item instanceof Sword ||
                    $item instanceof Armor ||
                    $item->getId() == Item::SHEARS ||
                    $item instanceof Pickaxe ||
                    $item instanceof Axe
                ) {
                    $event->setCancelled();
                }
                if (isset($this->ghost[$player->getName()])) {
                    $event->setCancelled();
                }
            }
        }
    }

    public function onInteract(PlayerInteractEvent $event)
    {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $item = $event->getItem();
        $itemN = $item->getCustomName();
        $v3 = $event->getTouchVector();
        $action = $event->getAction();
        $chest = $block->getLevel()->getTile($block);
        if (
            $player->getLevel()->getFolderName() ==
            $this->level->getFolderName()
        ) {
            if ($this->phase == self::PHASE_LOBBY) {
                if (
                    $action == $event::RIGHT_CLICK_BLOCK ||
                    $action == $event::RIGHT_CLICK_AIR
                ) {
                    if ($item->getId() == 355 && $item->getDamage() == 14) {
                        if ($this->plugin->config["waterdog"]["enabled"]) {
                            ServerManager::transferPlayer(
                                $player,
                                $this->plugin->config["waterdog"]["lobbyServer"]
                            );
                            $player->setDisplayName($player->getName());
                            $player->setScoreTag("");
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();
                        } else {
                            $player->teleport(
                                $this->plugin
                                    ->getServer()
                                    ->getDefaultLevel()
                                    ->getSpawnLocation()
                            );
                            $player->getInventory()->setHeldItemIndex(4);
                        }
                    }
                    if ($item->getId() == 145) {
                        $this->selectTeam($player);
                    }
                }
            }
            if ($this->phase == self::PHASE_RESTART) {
                if (
                    $action == $event::RIGHT_CLICK_BLOCK ||
                    $action == $event::RIGHT_CLICK_AIR
                ) {
                    if ($itemN == "§aNew game §7[use]") {
                        $player->getInventory()->setHeldItemIndex(1);
                        BedWars::instance()->joinToRandomArena($player);
                        $player->getInventory()->clearAll();
                        $player->getArmorInventory()->clearAll();
                        $player->getCursorInventory()->clearAll();
                    }
                    if ($item->getId() == 355 && $item->getDamage() == 14) {
                        if ($this->plugin->config["waterdog"]["enabled"]) {
                            ServerManager::transferPlayer(
                                $player,
                                $this->plugin->config["waterdog"]["lobbyServer"]
                            );
                            $player->setDisplayName($player->getName());
                            $player->setScoreTag("");
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();
                        } else {
                            $player->teleport(
                                $this->plugin
                                    ->getServer()
                                    ->getDefaultLevel()
                                    ->getSpawnLocation()
                            );
                            $player->getInventory()->setHeldItemIndex(4);
                        }
                    }
                }
            }
            if ($this->phase == self::PHASE_GAME) {
                if (
                    $action == $event::RIGHT_CLICK_BLOCK ||
                    $action == $event::RIGHT_CLICK_AIR
                ) {
                    if ($itemN == "§eTeleporter §7[use]") {
                        $this->playerlist($player);
                        $player->getInventory()->setHeldItemIndex(1);
                    }
                    if ($itemN == "§aNew game §7[use]") {
                        $player->getInventory()->setHeldItemIndex(1);
                        BedWars::instance()->joinToRandomArena($player);
                        $player->getInventory()->clearAll();
                        $player->getArmorInventory()->clearAll();
                        $player->getCursorInventory()->clearAll();
                    }
                    if ($item->getId() == 355 && $item->getDamage() == 14) {
                        if ($this->plugin->config["waterdog"]["enabled"]) {
                            ServerManager::transferPlayer(
                                $player,
                                $this->plugin->config["waterdog"]["lobbyServer"]
                            );
                            $player->setDisplayName($player->getName());
                            $player->setScoreTag("");
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();
                        } else {
                            $player->teleport(
                                $this->plugin
                                    ->getServer()
                                    ->getDefaultLevel()
                                    ->getSpawnLocation()
                            );
                            $player->getInventory()->setHeldItemIndex(4);
                        }
                    }
                }
                if ($this->inGame($player)) {
                    $ih = $player->getInventory()->getItemInHand();
                    if ($item->getId() == Item::FIRE_CHARGE) {
                        $this->spawnFireball(
                            $player->add(0, $player->getEyeHeight(), 0),
                            $player->level,
                            $player
                        );
                        $this->addSound($player, "mob.blaze.shoot", 1);
                        $ih->setCount($ih->getCount() - 1);
                        $player->getInventory()->setItemInHand($ih);
                        $event->setCancelled();
                    }
                    if ($action == $event::RIGHT_CLICK_BLOCK) {
                        if ($block instanceof Bed) {
                            if (!$player->isSneaking()) {
                                $event->setCancelled();
                            }
                        }
                        if (
                            $item->getId() == Item::SPAWN_EGG &&
                            $item->getDamage() == 14
                        ) {
                            $this->spawnGolem(
                                $block->add(0, 1, 0),
                                $player->level,
                                $player
                            );
                            $ih->setCount($ih->getCount() - 1);
                            $player->getInventory()->setItemInHand($ih);
                            $event->setCancelled();
                        }
                    }
                    if (
                        $block->getId() == Block::LIT_FURNACE ||
                        $block->getId() == Block::CRAFTING_TABLE ||
                        $block->getId() == Block::BREWING_STAND_BLOCK ||
                        $block->getId() == Block::FURNACE
                    ) {
                        $event->setCancelled();
                    }
                    $ih = $player->getInventory()->getItemInHand();
                    if ($item->getId() == Item::COMPASS) {
                        $detected = $this->findNearestPlayer($player, 300);
                        $this->setCompassPosition(
                            $player,
                            $detected ?? $player->getLevel()->getSafeSpawn()
                        );
                        if (
                            $item->equalsExact(
                                $player->getInventory()->getItemInHand()
                            )
                        ) {
                            if (is_null($detected)) {
                                $player->sendMessage(
                                    "§7Can't find nearest player"
                                );
                            } else {
                                $player->sendMessage(
                                    "§7Set compass to nearest player"
                                );
                            }
                        }
                    }
                }
            }
        }
        if (
            $this->inGame($player) &&
            $event->getBlock()->getId() == Block::CHEST &&
            $this->phase == self::PHASE_LOBBY
        ) {
            $event->setCancelled(true);
            return;
        }

        if (!$block->getLevel()->getTile($block) instanceof Tile) {
            return;
        }

        $signPos = Position::fromObject(
            Vector3::fromString($this->data["joinsign"][0]),
            $this->plugin
                ->getServer()
                ->getLevelByName($this->data["joinsign"][1])
        );

        if (
            !$signPos->equals($block) ||
            $signPos->getLevel()->getId() != $block->getLevel()->getId()
        ) {
            return;
        }

        if ($this->phase == self::PHASE_GAME) {
            $player->sendMessage("§cduring game");
            return;
        }
        if ($this->phase == self::PHASE_RESTART) {
            $player->sendMessage("§cduring game");
            return;
        }

        if ($this->setup) {
            return;
        }

        $this->joinToArena($player);
    }

    /**
     * @param EntityDamageEvent $event
     */

    public function giveItem($damager, $player)
    {
        if (!$this->inGame($damager)) {
            return;
        }
        $inv = $player->getInventory();
        $gc = 0;
        $dc = 0;
        $ec = 0;
        $ic = 0;
        foreach ($inv->getContents() as $item) {
            if ($item->getId() == Item::IRON_INGOT) {
                $ic += $item->getCount();
            }
            if ($item->getId() == Item::GOLD_INGOT) {
                $gc += $item->getCount();
            }
            if ($item->getId() == Item::DIAMOND) {
                $dc += $item->getCount();
            }
            if ($item->getId() == Item::EMERALD) {
                $ec += $item->getCount();
            }
        }
        $dinv = $damager->getInventory();
        $dinv->addItem(Item::get(Item::IRON_INGOT, 0, $ic));
        $dinv->addItem(Item::get(Item::GOLD_INGOT, 0, $gc));
        $dinv->addItem(Item::get(Item::DIAMOND, 0, $dc));
        $dinv->addItem(Item::get(Item::EMERALD, 0, $ec));
        if ($ic > 0) {
            $damager->sendMessage("§f+{$ic} iron");
        }
        if ($gc > 0) {
            $damager->sendMessage("§6+{$gc} gold");
        }
        if ($dc > 0) {
            $damager->sendMessage("§b+{$dc} diamond");
        }
        if ($ec > 0) {
            $damager->sendMessage("§a+{$ec} emerald");
        }
        $inv->clearAll();
    }

    public function onDamage(EntityDamageEvent $event)
    {
        $player = $event->getEntity();
        $cause = $event->getCause();
        if ($this->phase == self::PHASE_GAME) {
            if ($event instanceof EntityDamageByEntityEvent) {
                $dmg = $event->getDamager();
                if (
                    $dmg instanceof Player &&
                    $player->getNameTag() == "§l§bITEM SHOP\n§r§aLEFT CLICK"
                ) {
                    if ($this->inGame($dmg)) {
                        if ($dmg->distance($player) < 5) {
                            $this->shopMenu($dmg);
                        }
                    }
                }
                if (
                    $dmg instanceof Player &&
                    $player->getNameTag() == "§l§bTEAM UPGRADE\n§r§aLEFT CLICK"
                ) {
                    if ($this->inGame($dmg)) {
                        if ($dmg->distance($player) < 5) {
                            $this->upgradeMenu($dmg);
                        }
                    }
                }
            }
        }
        if ($event->isCancelled()) {
            return;
        }
        if ($player instanceof Player) {
            if ($this->inGame($player)) {
                if ($this->phase == self::PHASE_GAME) {
                    if (
                        $cause == $event::CAUSE_ENTITY_EXPLOSION ||
                        $cause == $event::CAUSE_BLOCK_EXPLOSION
                    ) {
                        $event->setBaseDamage(0);
                    }
                    if ($event instanceof EntityDamageByEntityEvent) {
                        if ($this->inGame($event->getDamager())) {
                            if (
                                $this->getTeam($event->getDamager()) !==
                                $this->getTeam($player)
                            ) {
                                if (isset($this->invis[$player->getId()])) {
                                    $this->setInvis($player, false);
                                }
                                $this->plugin->damaged[
                                    $player->getName()
                                ] = $player;
                                $this->plugin->lastDamager[
                                    $player->getName()
                                ] = $event->getDamager();
                                $this->plugin->lastTime[
                                    $player->getName()
                                ] = 10;
                            } else {
                                $event->setCancelled();
                            }
                        }
                    } else {
                        if (isset($this->invis[$player->getId()])) {
                            $this->setInvis($player, true);
                        }
                    }
                    if (isset($this->plugin->lastDamager[$player->getName()])) {
                        $damager =
                            $this->plugin->lastDamager[$player->getName()];
                        if (
                            $damager instanceof Player &&
                            $player instanceof Player
                        ) {
                            if (
                                $player->getHealth() -
                                    $event->getFinalDamage() <=
                                0
                            ) {
                                $event->setCancelled();
                                if (!$this->hasTreasure($player)) {
                                    $this->plugin->addFinalKill($damager);
                                    $this->plugin->addTopFinalKill($damager);
                                    $this->plugin->addRewardKill($damager);
                                }
                                $this->plugin->addKill($damager);
                                $this->plugin->addRewardFKill($damager);
                                $player->getArmorInventory()->clearAll();
                                foreach ($this->level->getPlayers() as $pl) {
                                    $msg = "§r{$damager->getDisplayName()} §ckilled {$player->getDisplayName()}";
                                    if (!$this->hasTreasure($player)) {
                                        $txt = "§l§bFINAL KILL! ";
                                    } else {
                                        $txt = "";
                                    }
                                    $pl->sendMessage("{$msg} {$txt}");
                                }
                                if (!$this->hasTreasure($player)) {
                                    $this->spectator($player);
                                    if (
                                        isset(
                                            $this->finalkill[$damager->getId()]
                                        )
                                    ) {
                                        $this->finalkill[$damager->getId()]++;
                                    }
                                } else {
                                    $this->startRespawn($player);
                                    if (
                                        isset($this->kill[$damager->getName()])
                                    ) {
                                        $this->kill[$damager->getName()]++;
                                    }
                                }
                                $msg = "§r{$damager->getDisplayName()} §ckilled {$player->getDisplayName()}";
                                $this->addSound($damager, "random.levelup", 2);
                                $this->giveItem($damager, $player);
                                unset(
                                    $this->plugin->lastDamager[
                                        $player->getName()
                                    ]
                                );
                                unset(
                                    $this->plugin->lastTime[$player->getName()]
                                );
                                unset(
                                    $this->plugin->damaged[$player->getName()]
                                );
                            }
                        }
                    } else {
                        if (
                            $player->getHealth() - $event->getBaseDamage() <=
                            0
                        ) {
                            $event->setCancelled();
                            $txt = null;
                            if (!$this->hasTreasure($player)) {
                                $txt = "§l§bFINAL KILL! ";
                            } else {
                                $txt = "";
                            }
                            $this->broadcastMessage(
                                "{$player->getDisplayName()} §cdied in oopsie {$txt}"
                            );
                            $player->getArmorInventory()->clearAll();
                            $player->getInventory()->clearAll();
                            $txt = null;
                            if (!$this->hasTreasure($player)) {
                                $txt = "§l§bFINAL KILL! ";
                                $this->spectator($player);
                            } else {
                                $this->startRespawn($player);
                                $txt = "";
                            }
                        }
                    }
                }
            }
        }
        if ($player instanceof Player) {
            if (
                $player->getLevel()->getFolderName() ==
                $this->level->getFolderName()
            ) {
                if ($this->phase == self::PHASE_LOBBY) {
                    $event->setCancelled();
                }
                if ($this->phase == self::PHASE_RESTART) {
                    $event->setCancelled();
                }
            }
        }
    }

    public function onEntityMotion(EntityMotionEvent $event): void
    {
        $entity = $event->getEntity();
        if ($entity instanceof Generator) {
            $event->setCancelled(true);
        }
    }

    public function playerlist($player)
    {
        $api = Server::getInstance()
            ->getPluginManager()
            ->getPlugin("FormAPI");
        $form = $api->createSimpleForm(function (Player $player, $data = null) {
            $target = $data;
            if ($target === null) {
                return true;
            }
            $p = Server::getInstance()->getPlayerExact($target);
            if ($this->inGame($p)) {
                $player->teleport($p->asVector3());
            }
        });
        $form->setTitle("§l§aTELEPORTER");
        foreach ($player->getLevel()->getPlayers() as $online) {
            if ($this->inGame($online)) {
                $form->addButton("§8Teleport to §e" . "", $online->getName());
            }
        }
        $form->sendToPlayer($player);
    }

    public function setCompassPosition(Player $player, Position $position): void
    {
        $pk = new SetSpawnPositionPacket();
        $pk->x = $pk->x2 = $position->getFloorX();
        $pk->y = $pk->y2 = $position->getFloorY();
        $pk->z = $pk->z2 = $position->getFloorZ();
        $pk->spawnType = SetSpawnPositionPacket::TYPE_WORLD_SPAWN;
        $pk->dimension = DimensionIds::OVERWORLD;
        $player->sendDataPacket($pk);
    }

    public function findNearestPlayer(Player $player, int $range): ?Player
    {
        $nearestPlayer = null;
        $nearestPlayerDistance = $range;
        foreach ($this->players as $p) {
            $distance = $player->distance($p);
            if (
                $distance <= $range &&
                $distance < $nearestPlayerDistance &&
                $player !== $p &&
                $p->isAlive() &&
                !$p->isClosed() &&
                !$p->isFlaggedForDespawn() &&
                $this->getTeam($p) !== $this->getTeam($player)
            ) {
                $nearestPlayer = $p;
                $nearestPlayerDistance = $distance;
            }
        }
        return $nearestPlayer;
    }

    public function addGlobalSound(
        $player,
        string $sound = "",
        float $pitch = 1
    ) {
        $pk = new PlaySoundPacket();
        $pk->x = $player->getX();
        $pk->y = $player->getY();
        $pk->z = $player->getZ();
        $pk->volume = 2;
        $pk->pitch = $pitch;
        $pk->soundName = $sound;
        Server::getInstance()->broadcastPacket(
            $player->getLevel()->getPlayers(),
            $pk
        );
    }

    public function addSound($player, string $sound = "", float $pitch = 1)
    {
        $pk = new PlaySoundPacket();
        $pk->x = $player->getX();
        $pk->y = $player->getY();
        $pk->z = $player->getZ();
        $pk->volume = 2;
        $pk->pitch = $pitch;
        $pk->soundName = $sound;
        $player->dataPacket($pk);
    }

    public function stopSound($player, string $sound = "", bool $all = true)
    {
        $pk = new StopSoundPacket();
        $pk->soundName = $sound;
        $pk->stopAll = $all;
        Server::getInstance()->broadcastPacket(
            $player->getLevel()->getPlayers(),
            $pk
        );
    }

    /**
     * @param PlayerRespawnEvent $event
     */
    public function onRespawn(PlayerRespawnEvent $event)
    {
        $player = $event->getPlayer();
        if (isset($this->toRespawn[$player->getName()])) {
            $event->setRespawnPosition(
                $this->plugin
                    ->getServer()
                    ->getDefaultLevel()
                    ->getSpawnLocation()
            );
            $this->setGhost($player, false);
            unset($this->toRespawn[$player->getName()]);
        }
    }

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event)
    {
        $player = $event->getPlayer();
        if ($this->inGame($player)) {
            $this->disconnectPlayer($player, "");
            $player->setDisplayName($player->getName());
            $player->setScoreTag("");
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $this->plugin->resetTopFinalKill($player);
        }
    }

    public function upgradeGenerator($team, $player)
    {
        $pos = Vector3::fromString($this->data["treasure"][$team]);
        $this->teamgenerator[$team]++;
        foreach ($this->level->getEntities() as $g) {
            if ($g instanceof Generator) {
                if ($g->asVector3()->distance($pos) < 20) {
                    $g->Glevel = $g->Glevel + 1;
                }
            }
        }
        foreach ($this->players as $t) {
            if ($this->getTeam($t) == $team) {
                $lvl = $this->teamgenerator[$team] - 1;
                $t->sendMessage(
                    "{$player->getDisplayName()} §ahas bought §bgenerator §alevel §c" .
                        $lvl
                );
                $this->addSound($t, "random.levelup", 1);
            }
        }
    }

    public function upgradeArmor($team, $player)
    {
        $this->teamprotection[$team]++;
        foreach ($this->players as $pt) {
            if ($this->getTeam($pt) == $team) {
                $lvl = $this->teamprotection[$team] - 1;
                $this->addSound($pt, "random.levelup", 1);
                $this->setArmor($pt);
                $pt->sendMessage(
                    "{$player->getDisplayName()} §ahas bought §bresistance §alevel §c" .
                        $lvl
                );
            }
        }
    }

    public function upgradeHaste($team, $player)
    {
        $this->teamhaste[$team]++;
        foreach ($this->players as $pt) {
            if ($this->getTeam($pt) == $team) {
                $lvl = $this->teamhaste[$team] - 1;
                $this->addSound($pt, "random.levelup", 1);
                $pt->sendMessage(
                    "{$player->getDisplayName()} §ahas bought §bhaste §alevel §c" .
                        $lvl
                );
            }
        }
    }

    public function upgradeSword($team, $player)
    {
        $this->teamsharpness[$team]++;
        foreach ($this->players as $pt) {
            if ($this->getTeam($pt) == $team) {
                $this->addSound($pt, "random.levelup", 1);
                $this->setSword($pt, $pt->getInventory()->getItem(0));
                $pt->sendMessage(
                    "{$player->getDisplayName()} §ahas bought §bsharpness"
                );
            }
        }
    }

    public function upgradeHeal($team, $player)
    {
        $this->teamhealth[$team]++;
        foreach ($this->players as $pt) {
            if ($this->getTeam($pt) == $team) {
                $this->addSound($pt, "random.levelup", 1);
                $pt->sendMessage(
                    "{$player->getDisplayName()} §ahas bought §bheal pool"
                );
            }
        }
    }

    public function upgradeMenu($player)
    {
        $team = $this->getTeam($player);
        $trapprice = $this->traps[$team];
        $slevel = $this->teamsharpness[$team];
        $Slevel = str_replace(["0"], ["-"], "" . ($slevel - 1) . "");
        $plevel = $this->teamprotection[$team];
        $Plevel = str_replace(["0"], ["-"], "" . ($plevel - 1) . "");
        $hlevel = $this->teamhaste[$team];
        $Hlevel = str_replace(["0"], ["-"], "" . ($hlevel - 1) . "");
        $glevel = $this->teamgenerator[$team];
        $Glevel = str_replace(["0"], ["-"], "" . ($glevel - 1) . "");
        $htlevel = $this->teamhealth[$team];
        $HTlevel = str_replace(["0"], ["-"], "" . ($htlevel - 1) . "");
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName("Team Upgrade");
        //$menu->readonly();
        $inv = $menu->getInventory();
        $this->upgrade = $inv;
        $menu->setListener(
            InvMenu::readonly(function (
                DeterministicInvMenuTransaction $transaction
            ): void {
                $player = $transaction->getPlayer();
                $pinv = $player->getInventory();
                $item = $transaction->getItemClicked();
                $itemClickedWith = $transaction->getItemClickedWith();
                $inv = $transaction->getAction()->getInventory();
                $team = $this->getTeam($player);
                $action = $transaction->getAction();
                //$transaction->discard();
                if (
                    $item instanceof Sword &&
                    $item->getId() == Item::IRON_SWORD
                ) {
                    if (isset($this->teamsharpness[$team])) {
                        $g = $this->teamsharpness[$team];
                        $cost = 8 * $g;
                        if ($g == 2) {
                            return;
                        }
                        if (
                            $pinv->contains(Item::get(Item::DIAMOND, 0, $cost))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::DIAMOND, 0, $cost)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $this->upgradeSword($team, $player);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                }
                if (
                    $item instanceof Armor &&
                    $item->getId() == Item::IRON_CHESTPLATE
                ) {
                    if (isset($this->teamprotection[$team])) {
                        $g = $this->teamprotection[$team];
                        $cost = 5 * $g;
                        if ($g == 5) {
                            return;
                        }
                        if (
                            $pinv->contains(Item::get(Item::DIAMOND, 0, $cost))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::DIAMOND, 0, $cost)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $this->upgradeArmor($team, $player);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                }
                if ($item->getId() == Item::IRON_PICKAXE) {
                    if (isset($this->teamhaste[$team])) {
                        $g = $this->teamhaste[$team];
                        $cost = 4 * $g;
                        if ($g == 3) {
                            return;
                        }
                        if (
                            $pinv->contains(Item::get(Item::DIAMOND, 0, $cost))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::DIAMOND, 0, $cost)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $this->upgradeHaste($team, $player);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                }
                if ($item->getId() == Block::FURNACE) {
                    if (isset($this->teamgenerator[$team])) {
                        $g = $this->teamgenerator[$team];
                        $cost = 4 * $g;
                        if ($g == 5) {
                            return;
                        }
                        if (
                            $pinv->contains(Item::get(Item::DIAMOND, 0, $cost))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::DIAMOND, 0, $cost)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $this->upgradeGenerator($team, $player);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                }
                if ($item->getId() == Block::BEACON) {
                    if (isset($this->teamhealth[$team])) {
                        $g = $this->teamhealth[$team];
                        $cost = 2 * $g;
                        if ($g == 2) {
                            return;
                        }
                        if (
                            $pinv->contains(Item::get(Item::DIAMOND, 0, $cost))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::DIAMOND, 0, $cost)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $this->upgradeHeal($team, $player);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                }
                $trapprice = $this->traps[$team];
                if ($item->getId() == Block::TRIPWIRE_HOOK) {
                    if (isset($this->itstrap[$team])) {
                        return;
                    }
                    if (
                        $pinv->contains(Item::get(Item::DIAMOND, 0, $trapprice))
                    ) {
                        $pinv->removeItem(
                            Item::get(Item::DIAMOND, 0, $trapprice)
                        );
                        $this->addSound($player, "note.pling", 1);
                        $this->itstrap[$team] = $team;
                        foreach ($this->players as $pt) {
                            if ($this->getTeam($pt) == $team) {
                                $pt->sendMessage(
                                    "{$player->getDisplayName()} §ahas bought §bIt's Trap"
                                );
                            }
                        }
                        $this->traps[$team]++;
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                }
                if ($item->getId() == Item::FEATHER) {
                    if (isset($this->countertrap[$team])) {
                        return;
                    }
                    if (
                        $pinv->contains(Item::get(Item::DIAMOND, 0, $trapprice))
                    ) {
                        $pinv->removeItem(
                            Item::get(Item::DIAMOND, 0, $trapprice)
                        );
                        $this->addSound($player, "note.pling", 1);
                        $this->countertrap[$team] = $team;
                        foreach ($this->players as $pt) {
                            if ($this->getTeam($pt) == $team) {
                                $pt->sendMessage(
                                    "{$player->getDisplayName()} §ahas bought §bCounter Offensive Trap"
                                );
                            }
                        }
                        $this->traps[$team]++;
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                }
                if ($item->getId() == Block::LIT_REDSTONE_TORCH) {
                    if (isset($this->alarmtrap[$team])) {
                        return;
                    }
                    if (
                        $pinv->contains(Item::get(Item::DIAMOND, 0, $trapprice))
                    ) {
                        $pinv->removeItem(
                            Item::get(Item::DIAMOND, 0, $trapprice)
                        );
                        $this->addSound($player, "note.pling", 1);
                        $this->alarmtrap[$team] = $team;
                        foreach ($this->players as $pt) {
                            if ($this->getTeam($pt) == $team) {
                                $pt->sendMessage(
                                    "{$player->getDisplayName()} §ahas bought §bAlarm Trap"
                                );
                            }
                        }
                        $this->traps[$team]++;
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                }
                if ($item->getId() == Item::WOODEN_PICKAXE) {
                    if (isset($this->minertrap[$team])) {
                        return;
                    }
                    if (
                        $pinv->contains(Item::get(Item::DIAMOND, 0, $trapprice))
                    ) {
                        $pinv->removeItem(
                            Item::get(Item::DIAMOND, 0, $trapprice)
                        );
                        $this->addSound($player, "note.pling", 1);
                        $this->minertrap[$team] = $team;
                        foreach ($this->players as $pt) {
                            if ($this->getTeam($pt) == $team) {
                                $pt->sendMessage(
                                    "{$player->getDisplayName()} §ahas bought §bMiner Fatigue Trap"
                                );
                            }
                        }
                        $this->traps[$team]++;
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                }
                $trapprice = $this->traps[$team];
                $slevel = $this->teamsharpness[$team];
                $Slevel = str_replace(["0"], ["-"], "" . ($slevel - 1) . "");
                $plevel = $this->teamprotection[$team];
                $Plevel = str_replace(["0"], ["-"], "" . ($plevel - 1) . "");
                $hlevel = $this->teamhaste[$team];
                $Hlevel = str_replace(["0"], ["-"], "" . ($hlevel - 1) . "");
                $glevel = $this->teamgenerator[$team];
                $Glevel = str_replace(["0"], ["-"], "" . ($glevel - 1) . "");
                $htlevel = $this->teamhealth[$team];
                $HTlevel = str_replace(["0"], ["-"], "" . ($htlevel - 1) . "");
                $sharp = null;
                if ($slevel > 1) {
                    $sharp = "§cSharpness (max)";
                } else {
                    $sharp = "§aSharpness";
                }
                $inv->setItem(
                    11,
                    Item::get(Item::IRON_SWORD)
                        ->setCustomName("$sharp")
                        ->setLore([
                            "§bCurrent Level: §c{$Slevel}\n",
                            "§e8 Diamond\n",
                            "§fGive your team sharpness sword",
                        ])
                );
                $prot = null;
                if ($plevel > 4) {
                    $prot = "§cResistance (max)";
                } else {
                    $prot = "§aResistance";
                }
                $inv->setItem(
                    12,
                    Item::get(Item::IRON_CHESTPLATE)
                        ->setCustomName("$prot")
                        ->setLore([
                            "§bCurrent Level: §c{$Plevel}\n",
                            "§aLevel §c1 §f- §e5 Diamond",
                            "§aLevel §c2 §f- §e10 Diamond",
                            "§aLevel §c3 §f- §e15 Diamond",
                            "§aLevel §c4 §f- §e20 Diamond\n",
                            "§fGive your team protection armor",
                        ])
                );
                $haste = null;
                if ($hlevel > 1) {
                    $haste = "§cHaste (max)";
                } else {
                    $haste = "§aHaste";
                }
                $inv->setItem(
                    13,
                    Item::get(Item::IRON_PICKAXE)
                        ->setCustomName("$haste")
                        ->setLore([
                            "§bCurrent Level: §c{$Hlevel}\n",
                            "§aLevel §c1 §f- §e4 Diamond",
                            "§aLevel §c2 §f- §e8 Diamond\n",
                            "§fGive your team infinite haste",
                        ])
                );
                $gen = null;
                if ($glevel > 4) {
                    $gen = "§cGenerator (max)";
                } else {
                    $gen = "§aGenerator";
                }
                $inv->setItem(
                    14,
                    Item::get(Block::FURNACE)
                        ->setCustomName("$gen")
                        ->setLore([
                            "§bCurrent Level: §c{$Glevel}\n",
                            "§aLevel §c1 §f- §e4 Diamond (increase iron spawns 50%)",
                            "§aLevel §c2 §f- §e8 Diamond (increase gold spawns 50%)",
                            "§aLevel §c3 §f- §e12 Diamond (spawns emerald on generator)",
                            "§aLevel §c4 §f- §e16 Diamond (increase iron & gold spawns 100%)\n",
                            "§fIncrease your team generator spawns",
                        ])
                );
                $health = null;
                if ($htlevel > 1) {
                    $health = "§cHeal Pool (max)";
                } else {
                    $health = "§aHeal Pool";
                }
                $inv->setItem(
                    15,
                    Item::get(Block::BEACON)
                        ->setCustomName("$health")
                        ->setLore([
                            "§bCurrent Level: §c{$HTlevel}\n",
                            "§e2 Diamond\n",
                            "§fGive your team infinite regen nearby your base",
                        ])
                );
                $itstrap = null;
                $itsprice = null;
                if (isset($this->itstrap[$team])) {
                    $itsprice = "";
                    $itstrap = "§aActived";
                } else {
                    $itsprice = "§e{$trapprice} Diamond\n";
                    $itstrap = "§cDisabled";
                }
                $inv->setItem(
                    29,
                    Item::get(Block::TRIPWIRE_HOOK)
                        ->setCustomName("§eIt's Trap")
                        ->setLore([
                            "§bStatus: {$itstrap}\n",
                            "{$itsprice}",
                            "§fGive enemy slowness and blindness effect 8 seconds",
                        ])
                );
                $countertrap = null;
                $counterprice = null;
                if (isset($this->countertrap[$team])) {
                    $countertrap = "§aActived";
                    $counterprice = "";
                } else {
                    $countertrap = "§cDisabled";
                    $counterprice = "§e{$trapprice} Diamond\n";
                }
                $inv->setItem(
                    30,
                    Item::get(Item::FEATHER)
                        ->setCustomName("§eCounter Offensive Trap")
                        ->setLore([
                            "§bStatus: {$countertrap}\n",
                            "{$counterprice}",
                            "§fGive team jump boost II and speed effect 15 seconds",
                        ])
                );
                $alarmtrap = null;
                $alarmprice = null;
                if (isset($this->alarmtrap[$team])) {
                    $alarmtrap = "§aActived";
                    $alarmprice = "";
                } else {
                    $alarmtrap = "§cDisabled";
                    $alarmprice = "§e{$trapprice} Diamond\n";
                }
                $inv->setItem(
                    31,
                    Item::get(Block::LIT_REDSTONE_TORCH)
                        ->setCustomName("§eAlarm Trap")
                        ->setLore([
                            "§bStatus: {$alarmtrap}\n",
                            "{$alarmprice}",
                            "§fReveal invisible",
                        ])
                );
                $minertrap = null;
                $minerprice = null;
                if (isset($this->minertrap[$team])) {
                    $minertrap = "§aActived";
                    $minerprice = "";
                } else {
                    $minertrap = "§cDisabled";
                    $minerprice = "§e{$trapprice} Diamond\n";
                }
                $inv->setItem(
                    32,
                    Item::get(Item::WOODEN_PICKAXE)
                        ->setCustomName("§eMiner Fatigue Trap")
                        ->setLore([
                            "§bStatus: {$minertrap}\n",
                            "{$minerprice}",
                            "§fGive enemy mining fatigue effect 8 seconds",
                        ])
                );
            })
        );
        $sharp = null;
        if ($slevel > 1) {
            $sharp = "§cSharpness (max)";
        } else {
            $sharp = "§aSharpness";
        }
        $inv->setItem(
            11,
            Item::get(Item::IRON_SWORD)
                ->setCustomName("$sharp")
                ->setLore([
                    "§bCurrent Level: §c{$Slevel}\n",
                    "§e8 Diamond\n",
                    "§fGive your team sharpness sword",
                ])
        );
        $prot = null;
        if ($plevel > 4) {
            $prot = "§cResistance (max)";
        } else {
            $prot = "§aResistance";
        }
        $inv->setItem(
            12,
            Item::get(Item::IRON_CHESTPLATE)
                ->setCustomName("$prot")
                ->setLore([
                    "§bCurrent Level: §c{$plevel}\n",
                    "§aLevel §c1 §f- §e5 Diamond",
                    "§aLevel §c2 §f- §e10 Diamond",
                    "§aLevel §c3 §f- §e15 Diamond",
                    "§aLevel §c4 §f- §e20 Diamond\n",
                    "§fGive your team protection armor",
                ])
        );
        $haste = null;
        if ($hlevel > 1) {
            $haste = "§cHaste (max)";
        } else {
            $haste = "§aHaste";
        }
        $inv->setItem(
            13,
            Item::get(Item::IRON_PICKAXE)
                ->setCustomName("$haste")
                ->setLore([
                    "§bCurrent Level: §c{$Hlevel}\n",
                    "§aLevel §c1 §f- §e4 Diamond",
                    "§aLevel §c2 §f- §e8 Diamond\n",
                    "§fGive your team infinite haste",
                ])
        );
        $gen = null;
        if ($glevel > 4) {
            $gen = "§cGenerator (max)";
        } else {
            $gen = "§aGenerator";
        }
        $inv->setItem(
            14,
            Item::get(Block::FURNACE)
                ->setCustomName("$gen")
                ->setLore([
                    "§bCurrent Level: §c{$Glevel}\n",
                    "§aLevel §c1 §f- §e4 Diamond (increase iron spawns 50%)",
                    "§aLevel §c2 §f- §e8 Diamond (increase gold spawns 50%)",
                    "§aLevel §c3 §f- §e12 Diamond (spawns emerald on generator)",
                    "§aLevel §c4 §f- §e16 Diamond (increase iron & gold spawns 100%)\n",
                    "§fIncrease your team generator spawns",
                ])
        );
        $health = null;
        if ($htlevel > 1) {
            $health = "§cHeal Pool (max)";
        } else {
            $health = "§aHeal Pool";
        }
        $inv->setItem(
            15,
            Item::get(Block::BEACON)
                ->setCustomName("$health")
                ->setLore([
                    "§bCurrent Level: §c{$HTlevel}\n",
                    "§e2 Diamond\n",
                    "§fGive your team infinite regen nearby your base",
                ])
        );
        $itstrap = null;
        $itsprice = null;
        if (isset($this->itstrap[$team])) {
            $itsprice = "";
            $itstrap = "§aActived";
        } else {
            $itsprice = "§e{$trapprice} Diamond\n";
            $itstrap = "§cDisabled";
        }
        $inv->setItem(
            29,
            Item::get(Block::TRIPWIRE_HOOK)
                ->setCustomName("§eIt's Trap")
                ->setLore([
                    "§bStatus: {$itstrap}\n",
                    "{$itsprice}",
                    "§fGive enemy slowness and blindness effect 8 seconds",
                ])
        );
        $countertrap = null;
        $counterprice = null;
        if (isset($this->countertrap[$team])) {
            $countertrap = "§aActived";
            $counterprice = "";
        } else {
            $countertrap = "§cDisabled";
            $counterprice = "§e{$trapprice} Diamond\n";
        }
        $inv->setItem(
            30,
            Item::get(Item::FEATHER)
                ->setCustomName("§eCounter Offensive Trap")
                ->setLore([
                    "§bStatus: {$countertrap}\n",
                    "{$counterprice}",
                    "§fGive team jump boost II and speed effect 15 seconds",
                ])
        );
        $alarmtrap = null;
        $alarmprice = null;
        if (isset($this->alarmtrap[$team])) {
            $alarmtrap = "§aActived";
            $alarmprice = "";
        } else {
            $alarmtrap = "§cDisabled";
            $alarmprice = "§e{$trapprice} Diamond\n";
        }
        $inv->setItem(
            31,
            Item::get(Block::LIT_REDSTONE_TORCH)
                ->setCustomName("§eAlarm Trap")
                ->setLore([
                    "§bStatus: {$alarmtrap}\n",
                    "{$alarmprice}",
                    "§fReveal invisible",
                ])
        );
        $minertrap = null;
        $minerprice = null;
        if (isset($this->minertrap[$team])) {
            $minertrap = "§aActived";
            $minerprice = "";
        } else {
            $minertrap = "§cDisabled";
            $minerprice = "§e{$trapprice} Diamond\n";
        }
        $inv->setItem(
            32,
            Item::get(Item::WOODEN_PICKAXE)
                ->setCustomName("§eMiner Fatigue Trap")
                ->setLore([
                    "§bStatus: {$minertrap}\n",
                    "{$minerprice}",
                    "§fGive enemy mining fatigue effect 8 seconds",
                ])
        );
        $menu->send($player);
    }

    public function shopMenu($player)
    {
        $team = $this->getTeam($player);
        $meta = [
            "red" => 14,
            "blue" => 11,
            "yellow" => 4,
            "green" => 5,
        ];
        $menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $menu->setName("Item Shop");
        $inv = $menu->getInventory();
        //$menu->readonly();
        $this->shop = $inv;
        $menu->setListener(
            InvMenu::readonly(function (
                DeterministicInvMenuTransaction $transaction
            ): void {
                //$transaction->discard();
                $player = $transaction->getPlayer();
                $pinv = $player->getInventory();
                $item = $transaction->getItemClicked();
                $itemClickedWith = $transaction->getItemClickedWith();
                $inv = $transaction->getAction()->getInventory();
                $team = $this->getTeam($player);
                $action = $transaction->getAction();
                $in = $item->getCustomName();
                if (
                    in_array($in, [
                        "§fBlocks",
                        "§fMelee",
                        "§fArmor",
                        "§fTools",
                        "§fBow & Arrow",
                        "§fPotions",
                        "§fUtility",
                    ])
                ) {
                    $this->manageShop($player, $inv, $in);
                    return;
                }
                if ($item instanceof Sword && $in == "§aStone Sword") {
                    if (
                        !$pinv->contains(Item::get(Item::IRON_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::GOLD_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::DIAMOND_SWORD, 0, 1))
                    ) {
                        if (
                            $pinv->contains(Item::get(Item::IRON_INGOT, 0, 10))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::IRON_INGOT, 0, 10)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $sword = Item::get(Item::STONE_SWORD, 0, 1);
                            $this->setSword($player, $sword);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                    return;
                }
                if ($item instanceof Sword && $in == "§aIron Sword") {
                    if (
                        !$pinv->contains(Item::get(Item::IRON_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::GOLD_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::DIAMOND_SWORD, 0, 1))
                    ) {
                        if (
                            $pinv->contains(Item::get(Item::GOLD_INGOT, 0, 7))
                        ) {
                            $pinv->removeItem(
                                Item::get(Item::GOLD_INGOT, 0, 7)
                            );
                            $this->addSound($player, "note.pling", 1);
                            $sword = Item::get(Item::IRON_SWORD, 0, 1);
                            $this->setSword($player, $sword);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                    return;
                }
                if ($item instanceof Sword && $in == "§aDiamond Sword") {
                    if (
                        !$pinv->contains(Item::get(Item::IRON_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::GOLD_SWORD, 0, 1)) &&
                        !$pinv->contains(Item::get(Item::DIAMOND_SWORD, 0, 1))
                    ) {
                        if ($pinv->contains(Item::get(Item::EMERALD, 0, 3))) {
                            $pinv->removeItem(Item::get(Item::EMERALD, 0, 3));
                            $this->addSound($player, "note.pling", 1);
                            $sword = Item::get(Item::DIAMOND_SWORD, 0, 1);
                            $this->setSword($player, $sword);
                        } else {
                            $this->addSound($player, "note.bass", 1);
                        }
                    }
                    return;
                }
                if ($in == "§aShears") {
                    if (isset($this->shear[$player->getName()])) {
                        return;
                    }
                    if ($pinv->contains(Item::get(Item::IRON_INGOT, 0, 20))) {
                        $pinv->removeItem(Item::get(Item::IRON_INGOT, 0, 20));
                        $this->addSound($player, "note.pling", 1);
                        $this->shear[$player->getName()] = $player;
                        $sword = $pinv->getItem(0);
                        $this->setSword($player, $sword);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($in == "§aKnockback Stick") {
                    if ($pinv->contains(Item::get(Item::GOLD_INGOT, 0, 5))) {
                        $pinv->removeItem(Item::get(Item::GOLD_INGOT, 0, 5));
                        $this->addSound($player, "note.pling", 1);
                        $stick = Item::get(Item::STICK, 0, 1);
                        $stick->setCustomName("§aKnockback Stick");
                        $stick->addEnchantment(
                            new EnchantmentInstance(
                                Enchantment::getEnchantment(
                                    Enchantment::KNOCKBACK
                                ),
                                1
                            )
                        );
                        $pinv->addItem($stick);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($in == "§aBow (Power I)") {
                    if ($pinv->contains(Item::get(Item::GOLD_INGOT, 0, 24))) {
                        $pinv->removeItem(Item::get(Item::GOLD_INGOT, 0, 24));
                        $this->addSound($player, "note.pling", 1);
                        $bow = Item::get(Item::BOW, 0, 1);
                        $bow->addEnchantment(
                            new EnchantmentInstance(
                                Enchantment::getEnchantment(Enchantment::POWER),
                                1
                            )
                        );
                        $pinv->addItem($bow);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($in == "§aBow (Power I, Punch I)") {
                    if ($pinv->contains(Item::get(Item::EMERALD, 0, 2))) {
                        $pinv->removeItem(Item::get(Item::EMERALD, 0, 2));
                        $this->addSound($player, "note.pling", 1);
                        $bow = Item::get(Item::BOW, 0, 1);
                        $bow->addEnchantment(
                            new EnchantmentInstance(
                                Enchantment::getEnchantment(Enchantment::POWER),
                                1
                            )
                        );
                        $bow->addEnchantment(
                            new EnchantmentInstance(
                                Enchantment::getEnchantment(Enchantment::PUNCH),
                                1
                            )
                        );
                        $pinv->addItem($bow);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($item instanceof Armor && $in == "§aChainmail Set") {
                    if (
                        isset($this->armor[$player->getName()]) &&
                        in_array($this->armor[$player->getName()], [
                            "iron",
                            "diamond",
                        ])
                    ) {
                        return;
                    }
                    if ($pinv->contains(Item::get(Item::IRON_INGOT, 0, 40))) {
                        $pinv->removeItem(Item::get(Item::IRON_INGOT, 0, 40));
                        $this->addSound($player, "note.pling", 1);
                        $this->armor[$player->getName()] = "chainmail";
                        $this->setArmor($player);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($item instanceof Armor && $in == "§aIron Set") {
                    if (
                        isset($this->armor[$player->getName()]) &&
                        in_array($this->armor[$player->getName()], ["diamond"])
                    ) {
                        return;
                    }
                    if ($pinv->contains(Item::get(Item::GOLD_INGOT, 0, 12))) {
                        $pinv->removeItem(Item::get(Item::GOLD_INGOT, 0, 12));
                        $this->addSound($player, "note.pling", 1);
                        $this->armor[$player->getName()] = "iron";
                        $this->setArmor($player);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                if ($item instanceof Armor && $in == "§aDiamond Set") {
                    if (
                        isset($this->armor[$player->getName()]) &&
                        in_array($this->armor[$player->getName()], ["diamond"])
                    ) {
                        return;
                    }
                    if ($pinv->contains(Item::get(Item::EMERALD, 0, 6))) {
                        $pinv->removeItem(Item::get(Item::EMERALD, 0, 6));
                        $this->addSound($player, "note.pling", 1);
                        $this->armor[$player->getName()] = "diamond";
                        $this->setArmor($player);
                    } else {
                        $this->addSound($player, "note.bass", 1);
                    }
                    return;
                }
                $this->buyItem($item, $player);
                if ($item instanceof Pickaxe) {
                    $pickaxe = $this->getPickaxeByTier($player);
                    $inv->setItem(20, $pickaxe);
                }
                if ($item instanceof Axe) {
                    $axe = $this->getAxeByTier($player);
                    $inv->setItem(21, $axe);
                }
            })
        );
        // Main Menu //
        $inv->setItem(
            1,
            Item::get(Block::WOOL, $meta[$team], 1)->setCustomName("§fBlocks")
        );
        $inv->setItem(
            2,
            Item::get(Item::GOLDEN_SWORD, 0, 1)->setCustomName("§fMelee")
        );
        $inv->setItem(
            3,
            Item::get(Item::CHAINMAIL_BOOTS, 0, 1)->setCustomName("§fArmor")
        );
        $inv->setItem(
            4,
            Item::get(Item::STONE_PICKAXE, 0, 1)->setCustomName("§fTools")
        );
        $inv->setItem(
            5,
            Item::get(Item::BOW, 0, 1)->setCustomName("§fBow & Arrow")
        );
        $inv->setItem(
            6,
            Item::get(Item::BREWING_STAND, 0, 1)->setCustomName("§fPotions")
        );
        $inv->setItem(
            7,
            Item::get(Block::TNT, 0, 1)->setCustomName("§fUtility")
        );
        // Block Menu //
        $this->manageShop($player, $inv, "§fBlocks");
        $menu->send($player);
    }

    public function manageShop($player, $inv, $type)
    {
        $team = $this->getTeam($player);
        $meta = [
            "red" => 14,
            "blue" => 11,
            "yellow" => 4,
            "green" => 5,
        ];
        // BLOCKS //
        if ($type == "§fBlocks") {
            $inv->setItem(
                19,
                Item::get(Block::WOOL, $meta[$team], 16)
                    ->setLore(["§f4 Iron"])
                    ->setCustomName("§aWool")
            );
            $inv->setItem(
                20,
                Item::get(Block::TERRACOTTA, $meta[$team], 16)
                    ->setLore(["§f12 Iron"])
                    ->setCustomName("§aTerracotta")
            );
            $inv->setItem(
                21,
                Item::get(241, $meta[$team], 4)
                    ->setLore(["§f12 Iron"])
                    ->setCustomName("§aStained Glass")
            );
            $inv->setItem(
                22,
                Item::get(Block::END_STONE, 0, 12)
                    ->setLore(["§f24 Iron"])
                    ->setCustomName("§aEnd Stone")
            );
            $inv->setItem(
                23,
                Item::get(Block::LADDER, 0, 16)
                    ->setLore(["§f4 Iron"])
                    ->setCustomName("§aLadder")
            );
            $inv->setItem(
                24,
                Item::get(5, 0, 16)
                    ->setLore(["§64 Gold"])
                    ->setCustomName("§aPlank")
            );
            $inv->setItem(
                25,
                Item::get(Block::OBSIDIAN, 0, 4)
                    ->setLore(["§24 Emerald"])
                    ->setCustomName("§aObsidian")
            );
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        // SWORD //
        if ($type == "§fMelee") {
            $inv->setItem(
                19,
                Item::get(Item::STONE_SWORD, 0, 1)
                    ->setLore(["§f10 Iron"])
                    ->setCustomName("§aStone Sword")
            );
            $inv->setItem(
                20,
                Item::get(Item::IRON_SWORD, 0, 1)
                    ->setLore(["§67 Gold"])
                    ->setCustomName("§aIron Sword")
            );
            $inv->setItem(
                21,
                Item::get(Item::DIAMOND_SWORD, 0, 1)
                    ->setLore(["§23 Emerald"])
                    ->setCustomName("§aDiamond Sword")
            );
            $stick = Item::get(Item::STICK, 0, 1);
            $stick->setLore(["§65 Gold"]);
            $stick->setCustomName("§aKnockback Stick");
            $stick->addEnchantment(
                new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::KNOCKBACK),
                    1
                )
            );
            $inv->setItem(22, $stick);
            $inv->setItem(23, Item::get(0));
            $inv->setItem(24, Item::get(0));
            $inv->setItem(25, Item::get(0));
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        // ARMOR //
        if ($type == "§fArmor") {
            $inv->setItem(
                19,
                Item::get(Item::CHAINMAIL_BOOTS, 0, 1)
                    ->setLore(["§f40 Iron"])
                    ->setCustomName("§aChainmail Set")
            );
            $inv->setItem(
                20,
                Item::get(Item::IRON_BOOTS, 0, 1)
                    ->setLore(["§612 Gold"])
                    ->setCustomName("§aIron Set")
            );
            $inv->setItem(
                21,
                Item::get(Item::DIAMOND_BOOTS, 0, 1)
                    ->setLore(["§26 Emerald"])
                    ->setCustomName("§aDiamond Set")
            );
            $inv->setItem(22, Item::get(0));
            $inv->setItem(23, Item::get(0));
            $inv->setItem(24, Item::get(0));
            $inv->setItem(25, Item::get(0));
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        if ($type == "§fTools") {
            $inv->setItem(
                19,
                Item::get(Item::SHEARS, 0, 1)
                    ->setLore(["§f20 Iron"])
                    ->setCustomName("§aShears")
            );
            $pickaxe = $this->getPickaxeByTier($player);
            $inv->setItem(20, $pickaxe);
            $axe = $this->getAxeByTier($player);
            $inv->setItem(21, $axe);
            $inv->setItem(22, Item::get(0));
            $inv->setItem(23, Item::get(0));
            $inv->setItem(24, Item::get(0));
            $inv->setItem(25, Item::get(0));
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        if ($type == "§fBow & Arrow") {
            $inv->setItem(
                19,
                Item::get(Item::ARROW, 0, 8)
                    ->setLore(["§62 Gold"])
                    ->setCustomName("§aArrow")
            );
            $inv->setItem(
                20,
                Item::get(Item::BOW, 0, 1)
                    ->setLore(["§612 Gold"])
                    ->setCustomName("§aBow")
            );
            $bowpower = Item::get(Item::BOW, 0, 1);
            $bowpower->setLore(["§624 Gold"]);
            $bowpower->setCustomName("§aBow (Power I)");
            $bowpower->addEnchantment(
                new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::POWER),
                    1
                )
            );
            $inv->setItem(21, $bowpower);
            $bowpunch = Item::get(Item::BOW, 0, 1);
            $bowpunch->setLore(["§22 Emerald"]);
            $bowpunch->setCustomName("§aBow (Power I, Punch I)");
            $bowpunch->addEnchantment(
                new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::POWER),
                    1
                )
            );
            $bowpunch->addEnchantment(
                new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::PUNCH),
                    1
                )
            );
            $inv->setItem(22, $bowpunch);
            $inv->setItem(23, Item::get(0));
            $inv->setItem(24, Item::get(0));
            $inv->setItem(25, Item::get(0));
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        if ($type == "§fPotions") {
            $inv->setItem(
                19,
                Item::get(373, 16, 1)
                    ->setLore(["§21 Emerald"])
                    ->setCustomName("§aSpeed Potion II (45 seconds)")
            );
            $inv->setItem(
                20,
                Item::get(373, 11, 1)
                    ->setLore(["§21 Emerald"])
                    ->setCustomName("§aJump Potion IV (45 seconds)")
            );
            $inv->setItem(
                21,
                Item::get(373, 7, 1)
                    ->setLore(["§22 Emerald"])
                    ->setCustomName("§aInvisibility Potion (30 seconds)")
            );
            $inv->setItem(22, Item::get(0));
            $inv->setItem(23, Item::get(0));
            $inv->setItem(24, Item::get(0));
            $inv->setItem(25, Item::get(0));
            $inv->setItem(28, Item::get(0));
            $inv->setItem(29, Item::get(0));
            $inv->setItem(30, Item::get(0));
        }
        if ($type == "§fUtility") {
            $inv->setItem(
                19,
                Item::get(Item::GOLDEN_APPLE, 0, 1)
                    ->setLore(["§63 Gold"])
                    ->setCustomName("§aGolden Apple")
            );
            $inv->setItem(
                20,
                Item::get(Item::SNOWBALL, 0, 1)
                    ->setLore(["§f40 Iron"])
                    ->setCustomName("§aBedbug")
            );
            $inv->setItem(
                21,
                Item::get(Item::SPAWN_EGG, 14, 1)
                    ->setLore(["§f120 Iron"])
                    ->setCustomName("§aDream Defender")
            );
            $inv->setItem(
                22,
                Item::get(Item::FIREBALL, 0, 1)
                    ->setLore(["§f40 Iron"])
                    ->setCustomName("§aFireball")
            );
            $inv->setItem(
                23,
                Item::get(Block::TNT, 0, 1)
                    ->setLore(["§68 Gold"])
                    ->setCustomName("§aTNT")
            );
            $inv->setItem(
                24,
                Item::get(Item::ENDER_PEARL, 0, 1)
                    ->setLore(["§24 Emerald"])
                    ->setCustomName("§aEnder Pearl")
            );
            $inv->setItem(
                25,
                Item::get(Item::COMPASS, 0, 1)
                    ->setLore(["§23 Emerald"])
                    ->setCustomName("§aPlayer Tracker")
            );
            $inv->setItem(
                28,
                Item::get(Item::BUCKET, 1, 1)
                    ->setLore(["§64 Gold"])
                    ->setCustomName("§aMagic Milk")
            );
            /*$inv->setItem(29, Item::get(Item::BUCKET, Block::FLOWING_WATER, 1)
        ->setLore(["§63 Gold"])
        ->setCustomName("§aWater Bucket")
        );*/
            //$inv->setItem(30, Item::get(Block::SPONGE, 0, 4)
            //->setLore(["§63 Gold"])
            //->setCustomName("§aSponge")
            //);
        }
    }

    public function screenAnimation($player, $id)
    {
        $pk = new OnScreenTextureAnimationPacket();
        $pk->effectId = $id;
        $player->sendDataPacket($pk);
    }

    public function getPickaxeByTier($player, bool $forshop = true)
    {
        if (isset($this->pickaxe[$player->getId()])) {
            $tier = $this->pickaxe[$player->getId()];
            $pickaxe = [
                1 => Item::get(Item::WOODEN_PICKAXE, 0, 1),
                2 => Item::get(Item::WOODEN_PICKAXE, 0, 1),
                3 => Item::get(Item::IRON_PICKAXE, 0, 1),
                4 => Item::get(Item::GOLDEN_PICKAXE, 0, 1),
                5 => Item::get(Item::DIAMOND_PICKAXE, 0, 1),
                6 => Item::get(Item::DIAMOND_PICKAXE, 0, 1),
            ];
            $enchant = [
                1 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    1
                ),
                2 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    1
                ),
                3 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    2
                ),
                4 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    2
                ),
                5 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    3
                ),
                6 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    3
                ),
            ];
            $name = [
                1 => "§aWooden Pickaxe (Efficiency I)",
                2 => "§aWooden Pickaxe (Efficiency I)",
                3 => "§aIron Pickaxe (Efficiency II)",
                4 => "§aGolden Pickaxe (Efficiency II)",
                5 => "§aDiamond Pickaxe (Efficiency III)",
                6 => "§aDiamond Pickaxe (Efficiency III)",
            ];
            $lore = [
                1 => [
                    "§f10 Iron",
                    "§eTier: §cI",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                2 => [
                    "§f10 Iron",
                    "§eTier: §cI",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                3 => [
                    "§f10 Iron",
                    "§eTier: §cII",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                4 => [
                    "§63 Gold",
                    "§eTier: §cIII",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                5 => [
                    "§66 Gold",
                    "§eTier: §cIV",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                6 => [
                    "§66 Gold",
                    "§eTier: §cV",
                    "§aMAXED",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
            ];
            $pickaxe[$tier]->addEnchantment($enchant[$tier]);
            if ($forshop) {
                $pickaxe[$tier]->setLore($lore[$tier]);
                $pickaxe[$tier]->setCustomName($name[$tier]);
            }
            return $pickaxe[$tier];
        }
    }

    public function getAxeByTier($player, bool $forshop = true)
    {
        if (isset($this->axe[$player->getId()])) {
            $tier = $this->axe[$player->getId()];
            $axe = [
                1 => Item::get(Item::WOODEN_AXE, 0, 1),
                2 => Item::get(Item::WOODEN_AXE, 0, 1),
                3 => Item::get(Item::STONE_AXE, 0, 1),
                4 => Item::get(Item::IRON_AXE, 0, 1),
                5 => Item::get(Item::DIAMOND_AXE, 0, 1),
                6 => Item::get(Item::DIAMOND_AXE, 0, 1),
            ];
            $enchant = [
                1 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    1
                ),
                2 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    1
                ),
                3 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    1
                ),
                4 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    2
                ),
                5 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    3
                ),
                6 => new EnchantmentInstance(
                    Enchantment::getEnchantment(Enchantment::EFFICIENCY),
                    3
                ),
            ];
            $name = [
                1 => "§aWooden Axe (Efficiency I)",
                2 => "§aWooden Axe (Efficiency I)",
                3 => "§aStone Axe (Efficiency I)",
                4 => "§aIron Axe (Efficiency II)",
                5 => "§aDiamond Axe (Efficiency III)",
                6 => "§aDiamond Axe (Efficiency III)",
            ];
            $lore = [
                1 => [
                    "§f10 Iron",
                    "§eTier: §cI",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                2 => [
                    "§f10 Iron",
                    "§eTier: §cI",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                3 => [
                    "§f10 Iron",
                    "§eTier: §cII",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                4 => [
                    "§63 Gold",
                    "§eTier: §cIII",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                5 => [
                    "§66 Gold",
                    "§eTier: §cIV",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
                6 => [
                    "§66 Gold",
                    "§eTier: §cV",
                    "§aMAXED",
                    "",
                    "§7This is an upgradable item.",
                    "§7It will lose 1 tier upon",
                    "§7death!",
                    "",
                    "§7You will permanently",
                    "§7respawn with at least the",
                    "§7lowest tier.",
                ],
            ];
            $axe[$tier]->addEnchantment($enchant[$tier]);
            if ($forshop) {
                $axe[$tier]->setLore($lore[$tier]);
                $axe[$tier]->setCustomName($name[$tier]);
            }
            return $axe[$tier];
        }
    }
    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onLevelChange(EntityLevelChangeEvent $event)
    {
        $player = $event->getEntity();
        if (!$player instanceof Player) {
            return;
        }
        if (isset($this->ghost[$player->getName()])) {
            unset($this->ghost[$player->getName()]);
        }
        if (isset($this->plugin->lastDamager[$player->getName()])) {
            unset($this->plugin->lastDamager[$player->getName()]);
            unset($this->plugin->lastTime[$player->getName()]);
            unset($this->plugin->damaged[$player->getName()]);
        }
        $player->setScoreTag("");
        if ($this->inGame($player)) {
            $this->disconnectPlayer($player, "");
            $this->plugin->resetTopFinalKill($player);
        }
    }

    public function buyItem(Item $item, Player $player)
    {
        if (!isset($item->getLore()[0])) {
            return;
        }
        $lore = \pocketmine\utils\TextFormat::clean($item->getLore()[0], true);
        $desc = explode(" ", $lore);
        $value = $desc[0];
        $valueType = $desc[1];
        $value = intval($value);
        if ($value < 1) {
            return;
        }
        if (!$item instanceof Pickaxe && !$item instanceof Axe) {
            $item = $item->setLore([]);
        }
        switch ($valueType) {
            case "Iron":
                $id = Item::IRON_INGOT;
                break;
            case "Gold":
                $id = Item::GOLD_INGOT;
                break;
            case "Emerald":
                $id = Item::EMERALD;
                break;
            default:
                break;
        }
        if ($item instanceof Pickaxe) {
            if (isset($this->pickaxe[$player->getId()])) {
                if ($this->pickaxe[$player->getId()] >= 6) {
                    return;
                }
            }
            $item = $item->setLore([]);
            $item->setUnbreakable(true);
            $c = 0;
            $i = 0;
            foreach ($player->getInventory()->getContents() as $slot => $isi) {
                if ($isi instanceof Pickaxe) {
                    $c++;
                    $i = $slot;
                }
            }
            $payment = Item::get($id, 0, $value);
            if ($player->getInventory()->contains($payment)) {
                $this->pickaxe[$player->getId()] = $this->getNextTier(
                    $player,
                    false
                );
                $player->getInventory()->removeItem($payment);
                $this->addSound($player, "note.pling", 1);
                if ($c > 0) {
                    $player->getInventory()->setItem($i, $item);
                } else {
                    $player->getInventory()->addItem($item);
                }
            } else {
                $this->addSound($player, "note.bass", 1);
            }
            return;
        }
        if ($item instanceof Axe) {
            if (isset($this->axe[$player->getId()])) {
                if ($this->axe[$player->getId()] >= 6) {
                    return;
                }
            }
            $item = $item->setLore([]);
            $item->setUnbreakable(true);
            $c = 0;
            $i = 0;
            foreach ($player->getInventory()->getContents() as $slot => $isi) {
                if ($isi instanceof Axe) {
                    $c++;
                    $i = $slot;
                }
            }
            $payment = Item::get($id, 0, $value);
            if ($player->getInventory()->contains($payment)) {
                $this->axe[$player->getId()] = $this->getNextTier(
                    $player,
                    true
                );
                $player->getInventory()->removeItem($payment);
                $this->addSound($player, "note.pling", 1);
                if ($c > 0) {
                    $player->getInventory()->setItem($i, $item);
                } else {
                    $player->getInventory()->addItem($item);
                }
            } else {
                $this->addSound($player, "note.bass", 1);
            }
            return;
        }
        $payment = Item::get($id, 0, $value);
        if ($player->getInventory()->contains($payment)) {
            $player->getInventory()->removeItem($payment);
            $it = Item::get(
                $item->getId(),
                $item->getDamage(),
                $item->getCount()
            );
            if (
                in_array($item->getCustomName(), [
                    "§aMagic Milk",
                    "§aWater Bucket",
                    "§aBedbug",
                    "§aDream Defender",
                    "§aFireball",
                    "§aInvisibility Potion (30 seconds)",
                    "§aSpeed Potion II (45 seconds)",
                    "§aJump Potion IV (45 seconds)",
                ])
            ) {
                $it->setCustomName("{$item->getCustomName()}");
            }
            if ($player->getInventory()->canAddItem($it)) {
                $player->getInventory()->addItem($it);
            } else {
                $player->getLevel()->dropItem($player, $it);
            }
            $this->addSound($player, "note.pling", 1);
        } else {
            $this->addSound($player, "note.bass", 1);
        }
    }

    public function getLessTier($player, bool $type)
    {
        if ($type) {
            if (isset($this->axe[$player->getId()])) {
                $tier = $this->axe[$player->getId()];
                $less = [
                    6 => 4,
                    5 => 4,
                    4 => 3,
                    3 => 2,
                    2 => 1,
                    1 => 1,
                ];
                return $less[$tier];
            }
        } else {
            if (isset($this->pickaxe[$player->getId()])) {
                $tier = $this->pickaxe[$player->getId()];
                $less = [
                    6 => 4,
                    5 => 4,
                    4 => 3,
                    3 => 2,
                    2 => 1,
                    1 => 1,
                ];
                return $less[$tier];
            }
        }
    }

    public function getNextTier($player, bool $type)
    {
        if ($type) {
            if (isset($this->axe[$player->getId()])) {
                $tier = $this->axe[$player->getId()];
                $less = [
                    1 => 3,
                    2 => 3,
                    3 => 4,
                    4 => 5,
                    5 => 6,
                    6 => 6,
                ];
                return $less[$tier];
            }
        } else {
            if (isset($this->pickaxe[$player->getId()])) {
                $tier = $this->pickaxe[$player->getId()];
                $less = [
                    1 => 3,
                    2 => 3,
                    3 => 4,
                    4 => 5,
                    5 => 6,
                    6 => 6,
                ];
                return $less[$tier];
            }
        }
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false)
    {
        if (!$this->data["enabled"]) {
            $this->plugin
                ->getLogger()
                ->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if (!$this->mapReset instanceof MapReset) {
            $this->mapReset = new MapReset($this);
        }

        if (!$restart) {
            $this->plugin
                ->getServer()
                ->getPluginManager()
                ->registerEvents($this, $this->plugin);
        } else {
            $this->scheduler->reloadTimer();
            $this->level = $this->mapReset->loadMap($this->data["level"]);
        }

        if (!$this->level instanceof Level) {
            $level = $this->mapReset->loadMap($this->data["level"]);
            if (!$level instanceof Level) {
                $this->plugin
                    ->getLogger()
                    ->error(
                        "Arena level wasn't found. Try save level in setup mode."
                    );
                $this->setup = true;
                return;
            }
            $this->level = $level;
        }

        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }

    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool
    {
        if (empty($this->data)) {
            return false;
        }
        if ($this->data["level"] == null) {
            return false;
        }
        if (
            !$this->plugin->getServer()->isLevelGenerated($this->data["level"])
        ) {
            return false;
        }
        if (!is_int($this->data["slots"])) {
            return false;
        }
        if (!is_array($this->data["spawns"])) {
            return false;
        }
        if (count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if (!is_array($this->data["joinsign"])) {
            return false;
        }
        if (count($this->data["joinsign"]) !== 2) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if ($loadArena) {
            $this->loadArena();
        }
        return true;
    }

    private function createBasicData()
    {
        $this->data = [
            "level" => null,
            "slots" => 2,
            "lobby" => null,
            "treasure" => [],
            "maxY" => null,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => [],
        ];
    }

    public function __destruct()
    {
        unset($this->scheduler);
    }
}
