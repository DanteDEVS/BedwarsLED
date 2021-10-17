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

namespace vixikhd\BedWars;

use pocketmine\command\{Command, CommandSender};
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\block\Block;
use pocketmine\utils\Config;
use pocketmine\level\Level;
use pocketmine\plugin\PluginBase;
use vixikhd\BedWars\arena\Arena;
use vixikhd\BedWars\arena\MapReset;
use vixikhd\BedWars\commands\BedWarsCommand;
use vixikhd\BedWars\math\{Vector3, Generator, Dinamite as TNT, Bedbug, Golem, Fireball};
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
use vixikhd\BedWars\provider\YamlDataProvider;
use pocketmine\tile\Tile;
use pocketmine\entity\{Skin, Entity};
use pocketmine\Player;
use pocketmine\Server;
use jojoe77777\FormAPI; 
use slapper\events\SlapperCreationEvent; 
use vixikhd\BedWars\libs\muqsit\invmenu\InvMenuHandler;
use onebone\economyapi\EconomyAPI;
use vixikhd\BedWars\provider\Mysql;

/**
 * Class BedWars
 * @package BedWars
 */
class BedWars extends PluginBase implements Listener {

    /** @var YamlDataProvider */
    public $dataProvider;
    
    public $config;
    
    public $lastDamager = [];
    public $lastTime = [];
    public $damaged = [];

    /** @var Command[] $commands */
    public $commands = [];

    /** @var Arena[] $arenas */
    public $arenas = [];

    /** @var Arena[] $setters */
    public $setters = [];

    /** @var int[] $setupData */
    public $setupData = [];
    
    public static $instance;

    public function onEnable() {
        self::$instance = $this;
        $this->saveResource("config.yml");
        $this->saveResource("diamond.png"); 
        $this->saveResource("emerald.png"); 
        $this->config = (new Config($this->getDataFolder() . "config.yml", Config::YAML))->getAll();    
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql = new Mysql($this);
        }
        @mkdir($this->getDataFolder() . "pdata");
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleRepeatingTask(new UpdateTask($this), 12000);
        $this->getScheduler()->scheduleRepeatingTask(new LastDamageTask($this), 20);
        $this->emptyArenaChooser = new EmptyArenaChooser($this);
        $this->dataProvider = new YamlDataProvider($this);
        $this->getServer()->getCommandMap()->register("Wars", $this->commands[] = new BedWarsCommand($this));
        if(!InvMenuHandler::isRegistered()){
            InvMenuHandler::register($this);
        }
        Entity::registerEntity(TNT::class, true);
        Entity::registerEntity(Generator::class, true);
        Entity::registerEntity(Bedbug::class, true);
        Entity::registerEntity(Golem::class, true);
        Entity::registerEntity(Fireball::class, true);

        //Plugin needed
        $this->economyapi = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        $this->slapper = $this->getServer()->getPluginManager()->getPlugin("Slapper");
        $this->scoreboard = $this->getServer()->getPluginManager()->getPlugin("Scoreboards");
        $this->magicwe = $this->getServer()->getPluginManager()->getPlugin("MagicWE2");
        $this->economyapi = $this->getServer()->getPluginManager()->getPlugin("Spectre");
        $this->formapi = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        $this->invcrash = $this->getServer()->getPluginManager()->getPlugin("InvCrashFix");
        $this->invmenu = $this->getServer()->getPluginManager()->getPlugin("InvMenu");
    }
    
    public static function instance(){
        return self::$instance;
    }
    
    public function getSkinFromFile($path){
        $img = imagecreatefrompng($path);
        $bytes = '';
        $l = (int) getimagesize($path)[1];
        for ($y = 0; $y < $l; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ((~((int)($rgba >> 24))) << 1) & 0xff;
                $r = ($rgba >> 16) & 0xff;
                $g = ($rgba >> 8) & 0xff;
                $b = $rgba & 0xff;
                $bytes .= chr($r) . chr($g) . chr($b) . chr($a);
            }
        }
        imagedestroy($img);
        return new Skin("Standard_CustomSlim", $bytes); 
    }

    public function onSlapperCreate(SlapperCreationEvent $ev) {
        $entity = $ev->getEntity();
        $line   = $entity->getNameTag();
        if ($line == "toptwwinsteam") {
            $entity->namedtag->setString("toptwwinsteam", "toptwwinsteam");
            $this->updateTopWin();
            
        } else if ($line == "topfinalkillsteam") {
            $entity->namedtag->setString("topfinalkillsteam", "topfinalkillsteam");
            $this->updateTopKills();
            
        } else if ($line == "topbwplayedteam") {
            $entity->namedtag->setString("topbwplayedteam", "topbwplayedteam");
            $this->updateTopPlayed();
        }
    }

    public function updateTopWin() {
        foreach ($this->getServer()->getLevels() as $level) {
            foreach ($level->getEntities() as $entity) {
                if (!empty($entity->namedtag->getString("toptwwinsteam", ""))) {
                    $topwin = $entity->namedtag->getString("toptwwinsteam", "");
                    if ($topwin == "toptwwinsteam") {
                        $data = new Config($this->getDataFolder() . "pdata/wins.yml", Config::YAML);
                        $swallet = $data->getAll();
                        $c = count($swallet);
                        $txt = "§bLEADERBOARD SOLO§e«\n§aTop 10 BEDWARS Wins\n";
                        arsort($swallet);
                        $i = 1;
                        foreach ($swallet as $name => $amount) {
                            
                            $txt .= "§a{$i}.§b§l{$name} §r§d- §c{$amount} §bwins\n";
                            if($i >= 10){
                                break;
                            }
                            ++$i;
                        }
                        $entity->setNameTag($txt);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_BOUNDING_BOX_HEIGHT, 3);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_SCALE, 0.0);
                    }
                }
            }
        }
    }
    
    public function updateTopKills() {
        foreach ($this->getServer()->getLevels() as $level) {
            foreach ($level->getEntities() as $entity) {
                if (!empty($entity->namedtag->getString("topfinalkillsteam", ""))) {
                    $topkills = $entity->namedtag->getString("topfinalkillsteam", "");
                    if ($topkills == "topfinalkillsteam") {
                        $data = new Config($this->getDataFolder() . "pdata/finalkills.yml", Config::YAML);
                        $swallet = $data->getAll();
                        $c = count($swallet);
                        $txt = "§e§l» §bLEADERBOARD SOLO§e«\n§aTop 10 BEDWARS Final Kills\n";
                        arsort($swallet);
                        $i = 1;
                        foreach ($swallet as $name => $amount) {
                            
                            $txt .= "§a{$i}.§b§l{$name} §r§d- §c{$amount} §bkills\n";
                            if($i >= 10){
                                break;
                            }
                            ++$i;
                        }
                        $entity->setNameTag($txt);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_BOUNDING_BOX_HEIGHT, 3);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_SCALE, 0.0);
                    }
                }
            }
        }
    }
    
    public function updateTopPlayed() {
        foreach ($this->getServer()->getLevels() as $level) {
            foreach ($level->getEntities() as $entity) {
                if (!empty($entity->namedtag->getString("topbwplayedsolo", ""))) {
                    $topkills = $entity->namedtag->getString("topbwplayedsolo", "");
                    if ($topkills == "topbwplayedsolo") {
                        $data = new Config($this->getDataFolder() . "pdata/played.yml", Config::YAML);
                        $swallet = $data->getAll();
                        $c = count($swallet);
                        $txt = "";
                        $txt .= "§e§l» §bLEADERBOARD SOLO§e«\n§aTop 10 BEDWARS Most Played\n";
                        arsort($swallet);
                        $i = 1;
                        foreach ($swallet as $name => $amount) {
                            
                            $txt .= "§a{$i}.§b§l{$name} §r§d- §c{$amount} §bgame\n";
                            if($i >= 10){
                                break;
                            }
                            ++$i;
                        }
                        $entity->setNameTag($txt);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_BOUNDING_BOX_HEIGHT, 3);
                        $entity->getDataPropertyManager()->setFloat(Entity::DATA_SCALE, 0.0);
                    }
                }
            }
        }
    }

    public function updateLeaderboard() {
        $this->starts = new Config($this->getDataFolder() . "pdata/wins.yml", Config::YAML);
        $this->saveResource("pdata/wins.yml");
        
        $this->finalkills = new Config($this->getDataFolder() . "pdata/kills.yml", Config::YAML);
        $this->saveResource("pdata/kills.yml");
        
        $this->played = new Config($this->getDataFolder() . "pdata/played.yml", Config::YAML);
        $this->saveResource("pdata/played.yml");
        
    }
    
    public function showTopFinalKill(Player $player) {
        $wallet = new Config($this->getDataFolder() . "pdata/topfinalkills.yml", Config::YAML);
        $txt = "§l§a=============================\n             §l§fBEDWARS§r\n\n";
        $player->sendMessage($txt);
        $swallet = $wallet->getAll();
        arsort($swallet);
        $i = 0;
        foreach ($swallet as $name => $amount) {
            $i++;
            if($i < 4 && $amount){
                switch($i){
                    case 1:
                      $one = "        §l§g1st Killer §r§7- §r{$name} §7- §7{$amount}\n";
                      $player->sendMessage($one);
                    break;
                    case 2:
                      $two =  "    §l§62nd Killer §r§7- §r{$name} §7- §7{$amount}\n";
                      $player->sendMessage($two);
                    break;
                    case 3:
                      $tree =  " §l§c3rd Killer §r§7- §r{$name} §7- §7{$amount}\n";
                      $player->sendMessage($tree);
                      break;
                    default:
                      $nihil = "          §l§c{i} No Body\n";
                    break;
                }
            }
        }
        $endtxt = "§l§a=============================";
        $player->sendMessage($endtxt);
    }

    public function onDisable() { 
        foreach ($this->getServer()->getLevels() as $level) {
            foreach ($level->getEntities() as $entity) {
                if (!empty($entity->namedtag->getString("toptwwinsteam", ""))) {
                    $lines    = explode("\n", $entity->getNameTag());
                    $lines[0] = $entity->namedtag->getString("toptwwinsteam", "");
                    $nametag  = implode("\n", $lines);
                    $entity->setNameTag($nametag);
                    
                } else if (!empty($entity->namedtag->getString("topfinalkillsteam", ""))) {
                    $lines    = explode("\n", $entity->getNameTag());
                    $lines[0] = $entity->namedtag->getString("topfinalkillsteam", "");
                    $nametag  = implode("\n", $lines);
                    $entity->setNameTag($nametag);
                    
                } else if (!empty($entity->namedtag->getString("topbwplayedteam", ""))) {
                    $lines    = explode("\n", $entity->getNameTag());
                    $lines[0] = $entity->namedtag->getString("topbwplayedteam", "");
                    $nametag  = implode("\n", $lines);
                    $entity->setNameTag($nametag);
                    
                }
            }
        }
    }

    /**
     * @param PlayerChatEvent $event
     */
    public function onChat(PlayerChatEvent $event) {
        $player = $event->getPlayer();

        if(!isset($this->setters[$player->getName()])) {
            return;
        }

        $event->setCancelled(\true);
        $args = explode(" ", $event->getMessage());

        /** @var Arena $arena */
        $arena = $this->setters[$player->getName()];

        switch ($args[0]) {
            case "help":
                $player->sendMessage("§a> BedWars setup help (1/1):\n".
                "§7help : Displays list of available setup commands\n" .
                "§7slots : Updates arena slots\n".
                "§7level : Sets arena level\n".
                "§7lobby : Sets Lobby Spawn\n".
                "§7spawn : Sets arena spawns\n".
                "§7bed : Sets bed team\n".
                "§7shop : Sets shop team\n".
                "§7maxy : Sets arena void\n".
                "§7joinsign : Sets arena join sign\n".
                "§7savelevel : Saves the arena level\n".
                "§7enable : Enables the arena");
                break;
            case "slots":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7slots <int: slots>");
                    break;
                }
                $arena->data["slots"] = (int)$args[1];
                $player->sendMessage("§a> Slots updated to $args[1]!");
                break;
            case "level":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7level <levelName>");
                    break;
                }
                if(!$this->getServer()->isLevelGenerated($args[1])) {
                    $player->sendMessage("§c> Level $args[1] does not found!");
                    break;
                }
                $player->sendMessage("§a> Arena level updated to $args[1]!");
                $arena->data["level"] = $args[1];
                break;
            case "spawn":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7setspawn <int: spawn>");
                    break;
                }
                if(!in_array($args[1], ["red", "blue"])){
                    break;
                }
                if($args[1] == "red") {
                    $arena->data["spawns"]["spawn-1"] = (new Vector3(floor($player->getX()) + 0.0, floor($player->getY()), floor($player->getZ()) + 0.0))->__toString();
                    $player->sendMessage("§a> Spawn $args[1] set to X: " . (string)floor($player->getX()) . " Y: " . (string)floor($player->getY()) . " Z: " . (string)floor($player->getZ()));
                    break;
                }
                if($args[1] == "blue") {
                    $arena->data["spawns"]["spawn-2"] = (new Vector3(floor($player->getX()) + 0.0, floor($player->getY()), floor($player->getZ()) + 0.0))->__toString();
                    $player->sendMessage("§a> Spawn $args[1] set to X: " . (string)floor($player->getX()) . " Y: " . (string)floor($player->getY()) . " Z: " . (string)floor($player->getZ()));
                    break;
                }
                break;
            case "bed":
                if(!isset($args[1])) {
                    $player->sendMessage("§cUsage: §7setspawn <int: spawn>");
                    break;
                }
                if(!in_array($args[1], ["red", "blue", "yellow", "green"])){
                    break;
                }
                $arena->data["treasure"]["{$args[1]}"] = (new Vector3(floor($player->getX()), floor($player->getY()), floor($player->getZ())))->__toString();
                $player->sendMessage("§a> Treasure $args[1] set to X: " . (string)floor($player->getX()) . " Y: " . (string)floor($player->getY()) . " Z: " . (string)floor($player->getZ()));
                break;
            case "shop":
                $player->getServer()->dispatchCommand($player, "slapper spawn villager §l§bITEM SHOP{line}§r§bLEFT CLICK");
                break;
            case "shopupgrade":
                $player->getServer()->dispatchCommand($player, "slapper spawn villager §l§bTEAM UPGRADE{line}§r§bLEFT CLICK");
                break;
            case "lobby":
                $arena->data["lobby"] = (new Vector3(floor($player->getX()) + 0.0, floor($player->getY()), floor($player->getZ()) + 0.0))->__toString();
                $player->sendMessage("§a> Lobby set to X: " . (string)floor($player->getX()) . " Y: " . (string)floor($player->getY()) . " Z: " . (string)floor($player->getZ()));
                break;
            case "maxy":
                $arena->data["maxY"] = round($player->getY());
                $player->sendMessage("§a> maxY set to: " . round($player->getY()));
                break;
            case "joinsign":
                $player->sendMessage("§a> Break block to set join sign!");
                $this->setupData[$player->getName()] = 0;
                break;
            case "savelevel":
                if(!$arena->level instanceof Level) {
                    $player->sendMessage("§c> Error when saving level: world not found.");
                    if($arena->setup) {
                        $player->sendMessage("§6§lERROR!§r§6 Coba pakai savelevel setelah aktifkan arena.");
                    }
                    break;
                }
                $arena->mapReset->saveMap($arena->level);
                $player->sendMessage("§a§lSUKSES!§r§a Level disimpan!");
                break;
            case "enable":
                if(!$arena->setup) {
                    $player->sendMessage("§6> Arena is already enabled!");
                    break;
                }

                if(!$arena->enable(false)) {
                    $player->sendMessage("§c> Could not load arena, there are missing information!");
                    break;
                }

                if($this->getServer()->isLevelGenerated($arena->data["level"])) {
                    if(!$this->getServer()->isLevelLoaded($arena->data["level"]))
                        $this->getServer()->loadLevel($arena->data["level"]);
                    if(!$arena->mapReset instanceof MapReset)
                        $arena->mapReset = new MapReset($arena);
                    $arena->mapReset->saveMap($this->getServer()->getLevelByName($arena->data["level"]));
                }

                $arena->loadArena(false);
                $player->sendMessage("§a> Arena enabled!");
                break;
            case "done":
                $player->sendMessage("§a> You have successfully left setup mode!");
                unset($this->setters[$player->getName()]);
                if(isset($this->setupData[$player->getName()])) {
                    unset($this->setupData[$player->getName()]);
                }
                break;
            default:
                $player->sendMessage("§6> You are in setup mode.\n".
                    "§7- use §lhelp §r§7to display available commands\n"  .
                    "§7- or §ldone §r§7to leave setup mode");
                break;
        }
    }

    /**
     * @param BlockBreakEvent $event
     */
    public function onBreak(BlockBreakEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        if(isset($this->setupData[$player->getName()])) {
            switch ($this->setupData[$player->getName()]) {
                case 0:
                    $this->setters[$player->getName()]->data["joinsign"] = [(new Vector3($block->getX(), $block->getY(), $block->getZ()))->__toString(), $block->getLevel()->getFolderName()];
                    $player->sendMessage("§a> Join sign updated!");
                    unset($this->setupData[$player->getName()]);
                    $event->setCancelled(\true);
                    break;
            }
        }
    }
    
    public function joinToRandomArena(Player $player) {
        $arena = $this->emptyArenaChooser->getRandomArena();
        if(!is_null($arena)) {
            $arena->joinToArena($player);
            return;
        }
        $player->sendMessage("§ccould not find available match now. try again later");
    } 
    
    public function addFinalKill(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "finalkill");
        } else {
            $kills = new Config($this->getDataFolder() . "pdata/finalkills.yml", Config::YAML);
            $k = $kills->get($name);
            $kills->set($name, $k + 1);
            $kills->save();
        }
    }
    
    public function getSWFinalKills($player){
        $kills = new Config($this->getDataFolder() . "pdata/finalkills.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "finalkill");
            } else {
                return $kills->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "finalkill");
            } else {
                return $kills->get($name);
            }
        }
    } 
    
    public function addKill(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "bwkill");
        } else {
            $kills = new Config($this->getDataFolder() . "pdata/kills.yml", Config::YAML);
         $k = $kills->get($name);
         $kills->set($name, $k + 1);
         $kills->save();
        }
    }
    
    public function getSWKills($player){
        $kills = new Config($this->getDataFolder() . "pdata/kills.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "bwkill");
            } else {
                return $kills->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "bwkill");
            } else {
                return $kills->get($name);
            }
        }
    }
    
    public function addBroken(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "broken");
        } else {
            $wins = new Config($this->getDataFolder() . "pdata/broken.yml", Config::YAML);
            $w = $wins->get($name);
            $wins->set($name, $w + 1);
            $wins->save();
        }
    } 
    
    public function getTreasureBroken($player){
        $wins = new Config($this->getDataFolder() . "pdata/broken.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "broken");
            } else {
                return $wins->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "broken");
            } else {
                return $wins->get($name);
            }
        }
    }
    
    public function addWin(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "win");
        } else {
            $wins = new Config($this->getDataFolder() . "pdata/wins.yml", Config::YAML);
            $w = $wins->get($name);
            $wins->set($name, $w + 1);
            $wins->save();
        }
    }
    
    public function getSWWins($player){
        $wins = new Config($this->getDataFolder() . "pdata/wins.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "win");
            } else {
                return $wins->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "win");
            } else {
                return $wins->get($name);
            }
        }
    }
    
    public function addLose(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "loses");
        } else {
            $loses = new Config($this->getDataFolder() . "pdata/loses.yml", Config::YAML);
            $l = $loses->get($name);
            $loses->set($name, $l + 1);
            $loses->save();
        }
    }
    
    public function getSWLoses($player){
        $loses = new Config($this->getDataFolder() . "pdata/loses.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "loses");
            } else {
                return $loses->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "loses");
            } else {
                return $loses->get($name);
            }
        }
    }
    
    public function addDeath(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "deaths");
        } else {
            $deaths = new Config($this->getDataFolder() . "pdata/deaths.yml", Config::YAML);
            $d = $deaths->get($name);
            $deaths->set($name, $d + 1);
            $deaths->save();
        }
    }
    
    public function getSWDeaths($player){
        $deaths = new Config($this->getDataFolder() . "pdata/deaths.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "deaths");
            } else {
                return $deaths->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "deaths");
            } else {
                return $deaths->get($name);
            }
        }
    }
    
    public function addPlayed(Player $player){
        $name = $player->getName();
        if($this->config["mysql-enabled"] == "true"){
            $this->mysql->setDataMysql($name, "played");
        } else {
            $played = new Config($this->getDataFolder() . "pdata/played.yml", Config::YAML);
            $p = $played->get($name);
            $played->set($name, $p + 1);
            $played->save();
        }
    }
    
    public function getSWPlayed($player){
        $played = new Config($this->getDataFolder() . "pdata/played.yml", Config::YAML);
        if($player instanceof Player){
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($player->getName(), "played");
            } else {
                return $played->get($player->getName());
            }
        } else {
            $name = $player->getName();
            if($this->config["mysql-enabled"] == "true"){
                return $this->mysql->getDataMysql($name, "played");
            } else {
                return $played->get($name);
            }
        }
    }

    public function addTopFinalKill(Player $player){
        $name = $player->getName();
        $kills = new Config($this->getDataFolder() . "pdata/topfinalkills.yml", Config::YAML);
        $k = $kills->get($name);
        $kills->set($name, $k + 1);
        $kills->save();
    }

    public function resetTopFinalKill(Player $player){
        $name = $player->getName();
        $kills = new Config($this->getDataFolder() . "pdata/topfinalkills.yml", Config::YAML);
        $kills->getAll();
        $kills->set($name, $kills->remove($name), "  ");
        $kills->set($name, $kills->remove($name), "0");
        $kills->save();
    }

    public function addRewardWin(Player $player) {
        $name = $player->getName();
        EconomyAPI::getInstance()->addMoney($name, 70); 
        $player->sendMessage("§6+70Coins! (Win)");
    } 

    public function addRewardBed(Player $player) {
        $name = $player->getName();
        EconomyAPI::getInstance()->addMoney($name, 24);
        $player->sendMessage("§6+24Coins! (Bed  broken)");
    }

    public function addRewardFkill(Player $player) {
        $name = $player->getName();
        EconomyAPI::getInstance()->addMoney($name, 12);
        $player->sendMessage("§6+12Coins! (Final Kill)");
    }

    public function addRewardKill(Player $player) {
        $name = $player->getName();
        EconomyAPI::getInstance()->addMoney($name, 6);
        $player->sendMessage("§6+6Coins! (Kill)");
    }
    
    public function onJoin(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $this->joinToRandomArena($player);
        $name = $player->getName();
        $kills = new Config($this->getDataFolder() . "pdata/kills.yml", Config::YAML);
        $deaths = new Config($this->getDataFolder() . "pdata/deaths.yml", Config::YAML);
        $wins = new Config($this->getDataFolder() . "pdata/wins.yml", Config::YAML);
        $loses = new Config($this->getDataFolder() . "pdata/loses.yml", Config::YAML);
        $played = new Config($this->getDataFolder() . "pdata/played.yml", Config::YAML);
        $fk = new Config($this->getDataFolder() . "pdata/finalkills.yml", Config::YAML); 
        $broken = new Config($this->getDataFolder() . "pdata/broken.yml", Config::YAML);
        if($this->config["mysql-enabled"] == "true"){
            if($this->mysql->isNewAccountMysql($player) == null){
                $this->mysql->createNewAccountMysql($player);
           }
        }
        if(!$broken->exists($name)){
            $broken->set($name, 0);
            $broken->save();
        } 
        if(!$kills->exists($name)){
            $kills->set($name, 0);
            $kills->save();
        }
        if(!$deaths->exists($name)){
            $deaths->set($name, 0);
            $deaths->save();
        }
        if(!$wins->exists($name)){
            $wins->set($name, 0);
            $wins->save();
        }
        if(!$loses->exists($name)){
            $loses->set($name, 0);
            $loses->save();
        }
        if(!$played->exists($name)){
            $played->set($name, 0);
            $played->save();
        }
        if(!$fk->exists($name)){
            $fk->set($name, 0);
            $fk->save();
        }
    }

    public function spawnSpectre(Player $sender) {
      $sender->getServer()->dispatchCommand($sender, "s s a");
      $sender->getServer()->dispatchCommand($sender, "s s b");
    }
    
  public function spectreJoin(Player $sender) {
      $sender->getServer()->dispatchCommand($sender, "s c a /bw random");
      $sender->getServer()->dispatchCommand($sender, "s c b /bw random");
    }
    
  public function spectreLeave(Player $sender) {
      $sender->getServer()->dispatchCommand($sender, "kick a");
      $sender->getServer()->dispatchCommand($sender, "kick b");
  }
}
