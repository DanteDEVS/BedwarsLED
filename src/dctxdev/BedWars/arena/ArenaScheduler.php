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

use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\entity\{Effect, EffectInstance};
use pocketmine\scheduler\Task;
use pocketmine\tile\Sign;
use vixikhd\BedWars\math\Time;
use vixikhd\BedWars\math\Vector3;
use Scoreboards\Scoreboards;

/**
 * Class ArenaScheduler
 * @package BedWars\arena
 */
class ArenaScheduler extends Task {

    /** @var Arena $plugin */
    protected $plugin;
    
    /** @var int $waitTime */
    public $waitTime = 30;
    
    public $upgradeNext = 1;
    public $upgradeTime = 5 * 60;
    
    public $bedgone = 10 * 60;
    public $suddendeath = 10 * 60;
    public $gameover = 10 * 60;

    /** @var int $restartTime */
    public $restartTime = 10;

    /** @var array $restartData */
    public $restartData = [];

    /**
     * ArenaScheduler constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * @param int $currentTick
     */
    public function onRun(int $currentTick) {
        $this->reloadSign();

        if($this->plugin->setup) return;

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                foreach($this->plugin->players as $player){
                    $team = $this->plugin->getTeam($player); 
                    if(!$player->hasEffect(14)){
                        if(isset($this->invis[$player->getId()])){
                            $this->plugin->setInvis($player, false);
                        }
                    }
                    $player->setFood(20);
                    $color = [
                        "red" => "§cRed",
                        "blue" => "§9Blue",
                    ];
                    
                    $map = $this->plugin->level->getFolderName();
                    $slots = count($this->plugin->players);
                    $date = date("d/m/Y");

                    $api = Scoreboards::getInstance();
                    $api->new($player, "BedWars", "§l§eBEDWARS");
                    $api->setLine($player, 1, "§7{$date}");
                    $api->setLine($player, 2, "    ");
                    $api->setLine($player, 3, "§fMap: §e{$map}");
                    $api->setLine($player, 4, "§fPlayer: §e{$slots}");
                    $api->setLine($player, 5, "   ");
                    $api->setLine($player, 6, "§fWaiting for player..");
                    $api->setLine($player, 7, "§fMode: §e1vs1");
                    $api->setLine($player, 8, " ");
                    $api->setLine($player, 9, "§fTeam: {$color[$team]}");
                    $api->setLine($player, 10, "  ");
                    $api->setLine($player, 11, "§ewww.shadow.com");
                }
                if(count($this->plugin->players) >= 8) {
                    if($this->waitTime > 0){
                      foreach($this->plugin->players as $player){
                    $team = $this->plugin->getTeam($player); 
                    if(!$player->hasEffect(14)){
                        if(isset($this->invis[$player->getId()])){
                            $this->plugin->setInvis($player, false);
                        }
                    }
                    $player->setFood(20);
                    $color = [
                        "red" => "§cRed",
                        "blue" => "§9Blue",
                    ];
                    
                    $map = $this->plugin->level->getFolderName();
                    $slots = count($this->plugin->players);
                    $starttime = $this->waitTime;
                    $date = date("d/m/Y");

                    $api = Scoreboards::getInstance();
                    $api->new($player, "BedWars", "§l§eBEDWARS");
                    $api->setLine($player, 1, "§7{$date}");
                    $api->setLine($player, 2, "    ");
                    $api->setLine($player, 3, "§fMap: §e{$map}");
                    $api->setLine($player, 4, "§fPlayer: §e{$slots}");
                    $api->setLine($player, 5, "   ");
                    $api->setLine($player, 6, "§fStarting game in §e{$starttime}");
                    $api->setLine($player, 7, "§fMode: §e1vs1");
                    $api->setLine($player, 8, " ");
                    $api->setLine($player, 9, "§fTeam: {$color[$team]}");
                    $api->setLine($player, 10, "  ");
                    $api->setLine($player, 11, "§ewww.shadow.com");
                }
                    $this->waitTime--;
                    }
                    if($this->waitTime <= 10) {
                        $this->plugin->broadcastMessage("§6{$this->waitTime}", Arena::MSG_TITLE);
                        $this->plugin->addGlobalSound($player, 'note.bass', 1);
                    }
                    if($this->waitTime == 1){
                        $this->plugin->startGame();
                    }
                } else {
                    $this->waitTime = 30;
                }
                break;
            case Arena::PHASE_GAME:
                foreach($this->plugin->respawn as $r) {
                    if($this->plugin->respawnC[$r->getName()] <= 1) {
                        unset($this->plugin->respawn[$r->getName()]);
                        unset($this->plugin->respawnC[$r->getName()]);
                        $this->plugin->respawn($r);
                    } else {
                        $this->plugin->respawnC[$r->getName()]--;
                        $r->sendSubtitle("§eRespawn in §c{$this->plugin->respawnC[$r->getName()]} §eseconds");
                    }
                }
                foreach($this->plugin->players as $milk){
                    if(isset($this->plugin->milk[$milk->getId()])){
                        if($this->plugin->milk[$milk->getId()] <= 0) {
                            unset($this->plugin->milk[$milk->getId()]);
                        } else {
                            $this->plugin->milk[$milk->getId()]--;
                        }
                    } 
                }
                $events = null;
                if($this->upgradeNext <= 4){
                    $this->upgradeTime--;
                    if($this->upgradeNext == 1){
                        $events = "§2Diamond II in: §e" . Time::calculateTime($this->upgradeTime) . "";
                    }
                    if($this->upgradeNext == 2){
                        $events = "§2Emerald II in: §e" . Time::calculateTime($this->upgradeTime) . "";
                    }
                    if($this->upgradeNext == 3){
                        $events = "§2Diamond III in: §e" . Time::calculateTime($this->upgradeTime) . "";
                    }
                    if($this->upgradeNext == 4){
                        $events = "§2Emerald III in: §e" . Time::calculateTime($this->upgradeTime) . "";
                    } 
                    if($this->upgradeTime == (0.0 * 60)){
                        $this->upgradeTime = 5 * 60;
                        $this->plugin->clearItem();
                        if($this->upgradeNext == 1){
                            $this->plugin->broadcastMessage("§bDiamond Generators §ehave been upgraded to Tier §cII");
                            $this->plugin->upgradeGeneratorTier("diamond", 2);
                            $this->plugin->level->setTime(5000); 
                        }
                        if($this->upgradeNext == 2){
                            $this->plugin->broadcastMessage("§2Emerald Generators §ehave been upgraded to Tier §cII");
                            $this->plugin->upgradeGeneratorTier("emerald", 2); 
                            $this->plugin->level->setTime(5000);  
                        }
                        if($this->upgradeNext == 3){
                            $this->plugin->broadcastMessage("§bDiamond Generators §ehave been upgraded to Tier §cIII");
                            $this->plugin->upgradeGeneratorTier("diamond", 3); 
                            $this->plugin->level->setTime(5000);  
                        }
                        if($this->upgradeNext == 4){
                            $this->plugin->broadcastMessage("§2Emerald Generators §ehave been upgraded to Tier §cIII");
                            $this->plugin->upgradeGeneratorTier("emerald", 3); 
                            $this->plugin->level->setTime(5000);
                        }
                        $this->upgradeNext++;
                    }
                } else {
                    if($this->bedgone > (0.0 * 60)){
                        $this->bedgone--;
                        $events = "§2Bedgone in: §e" . Time::calculateTime($this->bedgone) . "";
                    } else {
                        if($this->suddendeath > (0.0 * 60)){
                            $this->suddendeath--;
                        }
                        $events = "§2Sudden Death in: §e" . Time::calculateTime($this->suddendeath) . ""; 
                    }
                    if($this->bedgone == (0.0 * 60)){
                        $this->plugin->destroyAllBeds();
                        $this->plugin->level->setTime(5000);
                        $this->plugin->clearItem(); 
                    } 
                    if($this->suddendeath <= (0.0 * 60)){
                        $this->gameover--;
                        foreach($this->plugin->players as $victim){
                            foreach(["red", "blue"] as $t){
                                $pos = Vector3::fromString($this->plugin->data["treasure"][$t]);
                                if($victim->distance($pos) < 15){
                                    $eff = new EffectInstance(Effect::getEffect(Effect::WITHER), 60, 1);
                                    $eff->setVisible(false);
                                    $victim->addEffect($eff);
                                    $victim->sendTitle("§b", "§cthis area is too dangerous");
                                }
                            }
                        }
                        $events = "§2Game Over in: §e" . Time::calculateTime($this->gameover) . "";
                        if($this->gameover == (0.0 * 60)){
                            $this->plugin->draw();
                        }
                    }
                }
                foreach($this->plugin->players as $pt){ 
                    $team = $this->plugin->getTeam($pt);
                    $pos = Vector3::fromString($this->plugin->data["treasure"][$team]);
                    if(isset($this->plugin->teamhaste[$team])){
                        if($this->plugin->getTeam($pt) == $team){
                            if($this->plugin->teamhaste[$team] > 1){
                                $eff = new EffectInstance(Effect::getEffect(Effect::HASTE), 60, ($this->plugin->teamhaste[$team] - 2));
                                $eff->setVisible(false);
                                $pt->addEffect($eff);
                            }
                        }
                    }
                    if(isset($this->plugin->teamhealth[$team])){
                        if($this->plugin->getTeam($pt) == $team){
                            if($this->plugin->teamhealth[$team] > 1){
                                if($pt->distance($pos) < 10){
                                    $eff = new EffectInstance(Effect::getEffect(Effect::REGENERATION), 60, 0);
                                    $eff->setVisible(false);
                                    $pt->addEffect($eff);
                                }
                            }
                        }
                    }
                }
                foreach($this->plugin->players as $player){
                    $team = $this->plugin->getTeam($player); 
                    if(!$player->hasEffect(14)){
                        if(isset($this->invis[$player->getId()])){
                            $this->plugin->setInvis($player, false);
                        }
                    }
                    $player->setFood(20);
                    $color = [
                        "red" => "§cRed",
                        "blue" => "§9Blue",
                    ];
                    $redteam = [
                        "red" => "§7§lYou",
                        "blue" => " ",
                    ];
                    $blueteam = [
                        "red" => " ",
                        "blue" => "§7§lYou",
                    ];
                    $date = date("d/m/Y");
                    $kills = $this->plugin->kill[$player->getName()];
                    $fkills = $this->plugin->finalkill[$player->getId()];
                    $broken = $this->plugin->broken[$player->getId()];
                    $r = $this->plugin->teamStatus("red");
                    $a = $this->plugin->teamStatus("blue"); 
                    
                    $api = Scoreboards::getInstance();
                    $api->new($player, "BedWars", "§l§eBEDWARS");
                    $api->setLine($player, 1, "§7{$date}"); 
                    $api->setLine($player, 2, " "); 
                    $api->setLine($player, 3, "{$events}");
                    $api->setLine($player, 4, "§b§b§b ");
                    $api->setLine($player, 5, "§l§cR§r §fRed {$r} {$redteam[$team]}");
                    $api->setLine($player, 6, "§l§9B§r §fBlue {$a} {$blueteam[$team]}");
                    $api->setLine($player, 9, "  ");
                    $api->setLine($player, 10, "§fKills: §e{$kills}");
                    $api->setLine($player, 11, "§fFinal Kills: §e{$fkills}");
                    $api->setLine($player, 12, "§fBed Broken: §e{$broken}");
                    $api->setLine($player, 13, "   ");
                    $api->setLine($player, 14, "§ewww.shadow.com");
                    $api->getObjectiveName($player); 
                }
                $redcount = count($this->plugin->redteam);
                $aquacount = count($this->plugin->blueteam);
                if($limecount <= 0 && $aquacount <= 0 && $yellowcount <= 0){
                    $this->plugin->Wins("red");
                }
                if($redcount <= 0 && $limecount <= 0 && $yellowcount <= 0){
                    $this->plugin->Wins("blue");
                }
                break;
            case Arena::PHASE_RESTART:
                foreach($this->plugin->players as $player){
                    $team = $this->plugin->getTeam($player); 
                    if(!$player->hasEffect(14)){
                        if(isset($this->invis[$player->getId()])){
                            $this->plugin->setInvis($player, false);
                        }
                    }
                    $player->setFood(20);
                    $color = [
                        "red" => "§cRed",
                        "blue" => "§9Blue",
                    ];
                    $redteam = [
                        "red" => "§7§lYou",
                        "blue" => " ",
                    ];
                    $blueteam = [
                        "red" => " ",
                        "blue" => "§7§lYou",
                    ];
                    $date = date("d/m/Y");
                    $kills = $this->plugin->kill[$player->getName()];
                    $fkills = $this->plugin->finalkill[$player->getId()];
                    $broken = $this->plugin->broken[$player->getId()];
                    
                    $r = $this->plugin->teamStatus("red");
                    $a = $this->plugin->teamStatus("blue"); 
                    
                    $api = Scoreboards::getInstance();
                    $api->new($player, "BedWars", "§l§eBEDWARS");
                    $api->setLine($player, 1, "§7{$date}"); 
                    $api->setLine($player, 2, " "); 
                    $api->setLine($player, 3, "§fRestarting..");
                    $api->setLine($player, 4, "§b§b§b ");
                    $api->setLine($player, 5, "§l§cR§r §fRed {$r} {$redteam[$team]}");
                    $api->setLine($player, 6, "§l§9B§r §fBlue {$a} {$blueteam[$team]}");
                    $api->setLine($player, 9, "  ");
                    $api->setLine($player, 10, "§fKills: §e{$kills}");
                    $api->setLine($player, 11, "§fFinal Kills: §e{$fkills}");
                    $api->setLine($player, 12, "§fBed Broken: §e{$broken}");
                    $api->setLine($player, 13, "   ");
                    $api->setLine($player, 14, "§bwww.shadow.com");
                    $api->getObjectiveName($player); 
                }
                $this->restartTime--;

                switch ($this->restartTime) {
                    case 0:
                        foreach ($this->plugin->level->getPlayers() as $player){
                            $this->plugin->plugin->joinToRandomArena($player);
                        }
                        $this->plugin->loadArena(true);
                        $this->reloadTimer();
                        break;
                }
                break;
        }
    }

    public function reloadSign() {
        if(!is_array($this->plugin->data["joinsign"]) || empty($this->plugin->data["joinsign"])) return;

        $signPos = Position::fromObject(Vector3::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getLevelByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getLevel() instanceof Level || is_null($this->plugin->level)) return;

        $signText = [
            "§b§lBedWars",
            "§7[ §c? / ? §7]",
            "§cdisable",
            "§c"
        ];

        if($signPos->getLevel()->getTile($signPos) === null) return;

        if($this->plugin->setup || $this->plugin->level === null) {
            /** @var Sign $sign */
            $sign = $signPos->getLevel()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
            return;
        }

        $signText[1] = "§7[ §c" . count($this->plugin->players) . " / " . $this->plugin->data["slots"] . " §7]";

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->data["slots"]) {
                    $signText[2] = "§6Full";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                else {
                    $signText[2] = "§aJoin";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                break;
            case Arena::PHASE_GAME:
                $signText[2] = "§5InGame";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
            case Arena::PHASE_RESTART:
                $signText[2] = "§cRestarting...";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
        }

        /** @var Sign $sign */
        $sign = $signPos->getLevel()->getTile($signPos);
        if($sign instanceof Sign) // Chest->setText() doesn't work :D
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
    }

    public function reloadTimer() {
        $this->waitTime = 30;
        $this->upgradeNext = 1;
        $this->upgradeTime = 5 * 60;
        $this->bedgone = 10 * 60;
        $this->suddendeath = 10 * 60;
        $this->gameover = 10 * 60; 
        $this->restartTime = 10;
    }
}
