<?php

namespace  vixikhd\BedWars\provider;

use pocketmine\Player;
use pocketmine\Server;
use vixikhd\BedWars\BedWars;

abstract class DataProvider {


    public function __construct(BedWars $plugin){
        $this->plugin = $plugin;
    }

    abstract function setDataMysql(string $name, string $data);
    abstract function getDataMysql(string $name, string $data);
    abstract  function  createNewAccountMysql(Player $player);
    abstract  function isNewAccountMysql(Player $player);


}
