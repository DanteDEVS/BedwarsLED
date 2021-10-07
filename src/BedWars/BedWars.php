<?php


namespace BedWars;


use BedWars\{Entity\types\HumanEntity, Entity\types\TopsEntity, Entity\types\TopsEntitykill};
use BedWars\command\DefaultCommand;
use BedWars\game\entity\FakeItemEntity;
use BedWars\game\Game;
use BedWars\game\GameListener;
use BedWars\game\Team;
use BedWars\Tasks\EntityUpdate;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class BedWars extends PluginBase
{

    public const PREFIX = TextFormat::BOLD . TextFormat::DARK_RED . "" . TextFormat::RESET;
    public const TEAMS = [
        'blue' => "§1",
        'red' => "§c",
        'yellow' => "§e",
        "green" => "§a",
        "aqua" => "§b",
        "gold" => "§6",
        "white" => "§f"
    ];
    public const GENERATOR_PRIORITIES = [
        'gold' => ['item' => Item::GOLD_INGOT, 'spawnText' => false, 'spawnBlock' => false, 'refreshRate' => 13],
        'iron' => ['item' => Item::IRON_INGOT, 'spawnText' => false, 'spawnBlock' => false, 'refreshRate' => 8],
        'diamond' => ['item' => Item::DIAMOND, 'spawnText' => true, 'spawnBlock' => true, 'refreshRate' => 30],
        'emerald' => ['item' => Item::EMERALD, 'spawnText' => true, 'spawnBlock' => true, 'refreshRate' => 60]
    ];
    public static $instance;
    /** @var Game[] $games */
    public $games = array();
    /** @var array $signs */
    public $signs = array();
    /** @var array $bedSetup */
    public $bedSetup = array();
    /** @var string $serverWebsite */
    public $serverWebsite;
    /** @var int $staticStartTime */
    public $staticStartTime;
    /** @var int $staticRestartTime */
    public $staticRestartTime;
    public $eliminations = [];
    public $eliminationsb = [];

    public static function getConfigs(string $value)
    {
        return new Config(self::getInstance()->getDataFolder() . "{$value}.yml", Config::YAML);
    }

    public static function getInstance(): BedWars
    {
        return self::$instance;
    }

    public function onEnable(): void
    {
        self::$instance = $this;
        $this->saveDefaultConfig();
        $this->saveResource('kills.yml');
        $this->saveResource('wins.yml');
        Entity::registerEntity(TopsEntitykill::class, true);
        $this->loadEntitys();
        $this->loadTasks();
        $this->serverWebsite = $this->getConfig()->get('website');
        $this->staticStartTime = (int)$this->getConfig()->get('start-time');
        $this->staticRestartTime = (int)$this->getConfig()->get('restart-time');
        Entity::registerEntity(FakeItemEntity::class, true);
        @mkdir($concurrentDirectory = $this->getDataFolder() . "arenas");
        @mkdir($concurrentDirectory = $this->getDataFolder() . "skins");

        $this->saveResource("skins/264.png");
        $this->saveResource("skins/388.png");

        $this->getScheduler()->scheduleRepeatingTask(
            new SignUpdater($this), 20
        );
        $this->getServer()->getPluginManager()->registerEvents(new GameListener($this), $this);

        foreach (glob($this->getDataFolder() . "arenas/*.json") as $location) {
            $contents = file_get_contents($location);
            $jsonData = json_decode(mb_convert_encoding($contents, 'UTF-8', mb_detect_encoding($contents)), true);
            if (!$this->validateGame($jsonData)) {
                continue;
            }

            if (count($jsonData['signs']) > 0) {
                $this->signs[$jsonData['name']] = $jsonData['signs'];
            }

            $this->games[$jsonData['name']] = $game = new Game($this, $jsonData);

            $split = explode(":", $jsonData['lobby']);

            $game->setLobby(new Vector3((int)$split[0], (int)$split[1], (int)$split[2]));
            $game->setVoidLimit((int)$jsonData['void_y']);
        }

        $this->getServer()->getCommandMap()->register("bw", new DefaultCommand($this));
    }

    public function loadEntitys(): void
    {
        $values = [HumanEntity::class, TopsEntity::class];
        foreach ($values as $entitys) {
            Entity::registerEntity($entitys, true);
        }
        unset ($values);
    }

    public function loadTasks(): void
    {
        $values = [new EntityUpdate()];
        foreach ($values as $tasks) {
            $this->getScheduler()->scheduleRepeatingTask($tasks, 10);
        }
        unset($values);
    }

    public function validateGame(array $arenaData): bool
    {
        $requiredParams = [
            'name',
            'minPlayers',
            'playersPerTeam',
            'lobby',
            'world',
            'teamInfo',
            'generatorInfo',
            'lobby',
            'void_y',
            'mapName'
        ];

        $error = 0;
        foreach ($requiredParams as $param) {
            if (!array_key_exists($param, $arenaData)) {
                $error++;
            }
        }

        return (!$error) > 0;
    }

    /**
     * @param string $gameName
     * @return array|null
     */
    public function getArenaData(string $gameName): ?array
    {
        if (!$this->gameExists($gameName)) return null;

        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";

        $file = file_get_contents($location);
        return json_decode($file, true);
    }

    public function gameExists(string $gameName): bool
    {
        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";
        if (!is_file($location)) {
            return false;
        }

        return true;
    }

    public function addElimination(Player $player): void
    {
        if (isset($this->eliminations[$player->getName()])) {
            $this->eliminations[$player->getName()] = $this->eliminations[$player->getName()] + 1;
        }
    }

    public function getEliminations(Player $player): int
    {
        if (isset($this->eliminations[$player->getName()])) {
            return $this->eliminations[$player->getName()];
        } else {
            return $this->eliminations[$player->getName()] = 0;
        }
    }

    public function addEliminationb(Player $player): void
    {
        if (isset($this->eliminationsb[$player->getName()])) {
            $this->eliminationsb[$player->getName()] = $this->eliminationsb[$player->getName()] + 1;
        }
    }

    public function getEliminationsb(Player $player): int
    {
        if (isset($this->eliminationsb[$player->getName()])) {
            return $this->eliminationsb[$player->getName()];
        } else {
            return $this->eliminationsb[$player->getName()] = 0;
        }
    }

    public function writeArenaData(string $gameName, array $gameData): void
    {
        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";

        file_put_contents($location, json_encode($gameData));
    }

    public function isGameLoaded(string $gameName): bool
    {
        return isset($this->games[$gameName]);
    }

    public function objectToArray($d): object
    {
        if (is_array($d)) {
            return (object)array_map(__FUNCTION__, $d);
        }

        return $d;
    }

    public function loadArena(string $gameName)
    {
        $location = $this->getDataFolder() . "arenas/" . $gameName . ".json";
        if (!is_file($location)) {
            return "Game doesn't exist";
        }


        $file = file_get_contents($location);
        $jsonData = json_decode($file, true);
        if (!$this->validateGame($jsonData)) {
            return "Failed to validate arena";
        }
        $this->games[$jsonData['name']] = $game = new Game($this, $jsonData);
        return null;
    }

    public function getPlayerTeam(Player $player): ?Team
    {
        $game = $this->getPlayerGame($player);
        if ($game === null) {
            return null;
        }

        foreach ($game->teams as $team) {
            if (array_key_exists($player->getRawUniqueId(), $team->getPlayers())) {
                return $team;
            }
        }
        return null;
    }

    public function getPlayerGame(Player $player, bool $isSpectator = false): ?Game
    {
        $isSpectator = false;
        foreach ($this->games as $game) {
            if (isset($game->players[$player->getRawUniqueId()])) return $game;
            if (isset($game->spectators[$player->getRawUniqueId()])) return $game;
        }
        return null;
    }

    public function getGameByMap(string $arenaName): ?Game
    {
        foreach ($this->games as $game) {
            if ($game->getMapName() === $arenaName) {
                return $game;
            }
        }
        return null;
    }
}
