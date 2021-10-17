<?php

namespace  vixikhd\BedWars\provider;

use pocketmine\Server;
use pocketmine\Player;
use vixikhd\BedWars\BedWars;
use pocketmine\utils\Config;

class Mysql extends DataProvider{


    public function __construct(BedWars $plugin){
        parent::__construct($plugin);
        $this->plugin = $plugin;
        $config = (new Config($plugin->getDataFolder() . "config.yml", Config::YAML))->getAll();	
        $host = $config["host"];
        $user = $config["user"];
    	$pw = $config["password"];
        $db = $config["database"];
        $this->mysql = new \mysqli($host, $user, $pw, $db);
		$this->mysql->query("CREATE TABLE IF NOT EXISTS bedwars_stats (
    name VARCHAR(255) PRIMARY KEY,
    rilnem TEXT NOT NULL,
    finalkill TEXT NOT NULL,
    bwkill TEXT NOT NULL,
    broken TEXT NOT NULL,
    win TEXT NOT NULL,
    played TEXT NOT NULL,
    loses TEXT NOT NULL,
    deaths TEXT NOT NULL
);");
    }

    public function setDataMysql(string $name, string $data){
    	$this->mysql->query("UPDATE bedwars_stats SET $data = $data + 1 WHERE name = '" . strtolower($this->mysql->escape_string($name)) . "'");
	}

    public function setDataXpMysql(string $name, string $data){
        $this->mysql->query("UPDATE bedwars_stats SET $data = $data + 20 WHERE name = '" . strtolower($this->mysql->escape_string($name)) . "'");
    }

	public function getDataMysql(string $name, string $data){
        $result = $this->mysql->query("SELECT * FROM bedwars_stats WHERE name = '" . $this->mysql->escape_string($name) . "'");
        if ($result instanceof \mysqli_result){
            $data = $result->fetch_assoc();
            $result->free();
            if(isset($data["name"]) and strtolower($data["name"]) === $name){
                unset($data["name"]);
                return $data[$data];
            }
        }
        return null;
	}

	public function createNewAccountMysql(Player $player){
		$name = $player->getName();
        if($this->isNewAccountMysql($player) == null){
            $this->mysql->query("INSERT INTO bedwars_stats (name, rilnem, finalkill, bwkill, broken, win, played, loses, deaths) VALUES('" . $this->mysql->escape_string(strtolower($player->getName())) . "', '".$name."', '0', '0', '0', '0', '0', '0', '0')");
        }
	}

	public function isNewAccountMysql(Player $player){
		$name = trim(strtolower($player->getName()));
        $result = $this->mysql->query("SELECT * FROM bedwars_stats WHERE name = '".$this->mysql->escape_string($name)."'");
        if($result instanceof \mysqli_result){
            $data = $result->fetch_assoc();
            $result->free();
            if(isset($data["name"]) and strtolower($data["name"]) === $name){
                unset($data["name"]);
                return $data;
            }
        }
        return null;
	}
}
