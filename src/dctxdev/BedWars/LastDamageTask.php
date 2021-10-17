<?php

namespace vixikhd\BedWars;

use pocketmine\scheduler\Task;

class LastDamageTask extends Task {

    private $plugin;

    public function __construct(BedWars $plugin) {
        $this->plugin = $plugin;
    }
  
    public function onRun($tick) {
        foreach($this->plugin->damaged as $player) {     
        if($this->plugin->lastTime[$player->getName()] <= 0) {
            unset($this->plugin->lastDamager[$player->getName()]);
            unset($this->plugin->lastTime[$player->getName()]);
            unset($this->plugin->damaged[$player->getName()]);
        } else {
            $this->plugin->lastTime[$player->getName()]--;
        }
        }
    }
}