<?php


namespace BedWars\command;


use BedWars\{BedWars, Entity\EntityManager, Entity\types\HumanEntity, Entity\types\TopsEntity};
use BedWars\game\Game;
use BedWars\utils\Utils;
use pocketmine\block\Block;
use pocketmine\command\CommandSender;
use pocketmine\command\PluginCommand;
use pocketmine\level\Level;
use pocketmine\Player;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class DefaultCommand extends PluginCommand
{

	const ARGUMENT_LIST = [
		'create' => "[Map] [minPlayers] [playerPerTeam]",
		'addteam' => "[Map] [teamName]",
		'delete' => "[Map]",
		'setlobby' => "[Map]",
		'setpos' => "[Map] [team] [spawn,shop,upgrade]",
		'setbed' => "[Map] [team]",
		'setgenerator' => "[Map] [generator]",
		'join' => "[Map]",
		'load' => "[Map]",
		'setmap' => "[Map]",
		'entity' => "game,kills,wins [removegame,removekills,removewins]"
	];

	/**
	 * DefaultCommand constructor.
	 * @param BedWars $owner
	 */
	public function __construct(BedWars $owner)
	{
		parent::__construct("bw", $owner);
		parent::setDescription("BedWars command");
		parent::setPermission("bedwars.command");
	}

	/**
	 * @param CommandSender $sender
	 * @param string $commandLabel
	 * @param array $args
	 * @return bool|mixed|void
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args)
	{
		if (empty($args[0])) {
			$this->sendUsage($sender);
			return;
		}
		if ($sender instanceof Player) {
			switch (strtolower($args[0])) {
				case "list";
					$sender->sendMessage(TextFormat::BOLD . TextFormat::DARK_RED . "Arena List");
					foreach ($this->getPlugin()->games as $game) {
						$sender->sendMessage(TextFormat::GRAY . "- " . TextFormat::GREEN . $game->getName() . " [" . count($game->players) . "/" . $game->getMaxPlayers() . "]");
					}
					break;
				case "create";
					if (!$sender instanceof Player) {
						$sender->sendMessage(TextFormat::RED . "This command can be executed only in game");
						return;
					}

					if (count($args) < 3) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					if (array_key_exists($gameName, $this->getPlugin()->games)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Game called " . $gameName . " already exists!");
						return;
					}

					if (!is_int((int)$args[2])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "minPlayers must be a number!");
					}

					if (!is_int((int)$args[3])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "playersPerTeam must be a number!");
					}

					$minPlayers = (int)$args[2];
					$maxPlayers = (int)$args[3];

					$world = $sender->level;

					$dataStructure = [
						'name' => $gameName,
						'minPlayers' => $minPlayers,
						'playersPerTeam' => $maxPlayers,
						'world' => $world->getFolderName(),
						'signs' => [],
						'teamInfo' => [],
						'generatorInfo' => []
					];

					new Config($this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json", Config::JSON, $dataStructure);
					$sender->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Arena created!");

					break;
				case "addteam";
					if (count($args) < 3) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];

					$location = $this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json";
					if (!is_file($location)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$fileContent = file_get_contents($location);
					$jsonData = json_decode($fileContent, true);

					if (count($jsonData['teamInfo']) >= count(BedWars::TEAMS)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "You've reached the limit of teams per game!");
						return;
					}

					if (isset($jsonData['teamInfo'][$args[2]])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Team already exists!");
						return;
					}

					$jsonData['teamInfo'][$args[2]] = ['spawnPos' => '', 'bedPos' => '', 'shopPos'];

					file_put_contents($location, json_encode($jsonData));
					$sender->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Team added!");
					break;
				case "delete";
					if (count($args) < 2) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					if (!in_array($gameName, array_keys($this->getPlugin()->games))) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Game called " . $gameName . " doesn't exist!");
						return;
					}

					//close the arena if it's running
					$gameObject = $this->getPlugin()->games[$gameName];
					if (!$gameObject instanceof Game) {
						return; //wtf ??
					}

					$gameObject->stop();

					unlink($this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json");
					$sender->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Arena has been deleted!");

					break;
				case "setlobby";
					if (!$sender instanceof Player) {
						$sender->sendMessage(TextFormat::RED . "This command can be executed only in game");
						return;
					}

					if (count($args) < 2) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];

					$location = $this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json";
					if (!is_file($location)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$level = $sender->level;
					$void_y = Level::Y_MAX;
					foreach ($level->getChunks() as $chunk) {
						for ($x = 0; $x < 16; ++$x) {
							for ($z = 0; $z < 16; ++$z) {
								for ($y = 0; $y < $void_y; ++$y) {
									$block = $chunk->getBlockId($x, $y, $z);
									if ($block !== Block::AIR) {
										$void_y = $y;
										break;
									}
								}
							}
						}
					}
					--$void_y;

					$fileContent = file_get_contents($location);
					$jsonData = json_decode($fileContent, true, 512, JSON_THROW_ON_ERROR);
					$positionData = [
						'lobby' => $sender->getX() . ":" . $sender->getY() . ":" . $sender->getZ(),
						'void_y' => $void_y
					];

					file_put_contents($location, json_encode(array_merge($jsonData, $positionData), JSON_THROW_ON_ERROR));
					$sender->sendMessage(TextFormat::GREEN . "Registered Successfully!");
					break;
				case "setmap";
					if (!isset($args[1])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					$location = $this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json";
					if (!is_file($location)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}
					$fileContent = file_get_contents($location);
					$jsonData = json_decode($fileContent, true);
					$jsonData['mapName'] = $args[1];
					$jsonData['world'] = $args[1];
					file_put_contents($location, json_encode($jsonData));
					$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Map name was set to {$gameName}");
					break;
				case "setpos";
					if (count($args) < 3) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					$location = $this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json";
					if (!is_file($location)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$fileContent = file_get_contents($location);
					$jsonData = json_decode($fileContent, true);


					$teamName = $args[2];
					if (!isset($jsonData['teamInfo'][$args[2]])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Team doesn't exist!");
						return;
					}

					if (!in_array(strtolower($args[3]), array('spawn', 'shop', 'upgrade'))) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Invalid identifier");
						return;
					}

					$jsonData['teamInfo'][$teamName][strtolower($args[3]) . "Pos"] = $sender->getX() . ":" . $sender->getY() . ":" . $sender->getZ();

					file_put_contents($location, json_encode($jsonData));
					$sender->sendMessage(BedWars::PREFIX . TextFormat::GREEN . "Property updated");
					break;
				case "setbed";
					if (!$sender instanceof Player) {
						$sender->sendMessage(TextFormat::RED . "This command can be executed only in game");
						return;
					}

					if (count($args) < 2) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					$location = $this->getPlugin()->getDataFolder() . "arenas/" . $gameName . ".json";
					if (!is_file($location)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$fileContent = file_get_contents($location);
					$jsonData = json_decode($fileContent, true);

					$teamName = $args[2];
					if (!isset($jsonData['teamInfo'][$args[2]])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Team doesn't exists!");
						return;
					}

					$this->getPlugin()->bedSetup[$sender->getRawUniqueId()] = ['game' => $gameName, 'team' => $teamName, 'step' => 1];
					$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Select the bed by breaking it");
					break;
				case "setgenerator";
					if (!$sender instanceof Player) {
						$sender->sendMessage(TextFormat::RED . "This command can be executed only in game");
						return;
					}

					if (count($args) < 3) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					$gameName = $args[1];
					if (!$this->getPlugin()->gameExists($gameName)) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$generatorType = $args[2];
					if (!in_array($generatorType, array('iron', 'gold', 'emerald', 'diamond'))) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Generators: " . TextFormat::RED . "iron,gold,diamond,emerald");
						return;
					}

					$arenaData = $this->getPlugin()->getArenaData($gameName);
					$arenaData['generatorInfo'][$gameName][] = ['type' => $generatorType, 'position' => Utils::vectorToString("", $sender), 'game'];
					$this->getPlugin()->writeArenaData($gameName, $arenaData);

					$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Created new generator " . TextFormat::GREEN . "[game=" . $gameName . " | type=" . $generatorType . "]");
					break;
				case "join";

					if (!isset($args[1])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}
					$gameName = $args[1];

					if (!isset($this->getPlugin()->games[$gameName])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . "Arena doesn't exist");
						return;
					}

					$this->getPlugin()->games[$gameName]->join($sender);
					break;
				case "load";
					if (!isset($args[1])) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $this->generateSubCommandUsage($args[0]));
						return;
					}

					if (!is_null($error = $this->getPlugin()->loadArena($args[1]))) {
						$sender->sendMessage(BedWars::PREFIX . TextFormat::YELLOW . $error);
						return;
					}

					$sender->sendMessage(TextFormat::GREEN . "Arena {$args[1]} loaded");
					break;
				case 'entity':
					if ($sender->isOp()) {
						if (!empty($args[1])) {
							switch ($args[1]) {
								case 'game':
									$entity = new EntityManager();
									$entity->seGamebw($sender);
									$sender->sendMessage('§aEntity established successfully.');
									break;
								case 'kills':
									$entity = new EntityManager();
									$entity->setTopsbwkill($sender);
									$sender->sendMessage('§aEntity established successfully.');
									break;
								case 'wins':
									$entity = new EntityManager();
									$entity->setTopsbw($sender);
									$sender->sendMessage('§aEntity established successfully.');
									break;
								case 'removegame':
									foreach ($sender->getLevel()->getEntities() as $entity) {
										if ($entity instanceof HumanEntity) {
											$entity->kill();
										}
									}
									break;
								case 'removewins':
									foreach ($sender->getLevel()->getEntities() as $entity) {
										if ($entity instanceof TopsEntity) {
											$entity->kill();
										}
									}
									break;
								case 'removekills':
									foreach ($sender->getLevel()->getEntities() as $entity) {
										if ($entity instanceof TopsEntitykill) {
											$entity->kill();
										}
									}
									break;
							}
						} else {
							$sender->sendMessage('§c/bw entity');
						}
					} else {
						$sender->sendMessage('§cYou do not have permissions to execute this command.');
					}
					break;
			}
		}
	}

	/**
	 * @param CommandSender $sender
	 */
	private function sendUsage(CommandSender $sender): void
	{
		$sender->sendMessage(TextFormat::BOLD . TextFormat::AQUA . "BedWars Commands");
		$sender->sendMessage(TextFormat::GREEN . "/bw list " . TextFormat::YELLOW . "Display list of loaded games");
		$sender->sendMessage(TextFormat::GREEN . "/bw create " . TextFormat::YELLOW . "Create new game");
		$sender->sendMessage(TextFormat::GREEN . "/bw delete " . TextFormat::YELLOW . "Delete existing game");
		$sender->sendMessage(TextFormat::GREEN . "/bw setlobby " . TextFormat::YELLOW . "Set spawning position of a game");
		$sender->sendMessage(TextFormat::GREEN . "/bw setmap " . TextFormat::YELLOW . "Set name of map (on join sign/in-game)");
		$sender->sendMessage(TextFormat::GREEN . "/bw setpos " . TextFormat::YELLOW . "Set position [spawn,shop,upgrade] of a team");
		$sender->sendMessage(TextFormat::GREEN . "/bw setbed " . TextFormat::YELLOW . "Set bed position of a team");
		$sender->sendMessage(TextFormat::GREEN . "/bw setgenerator " . TextFormat::YELLOW . "Set generator of a team");
		$sender->sendMessage(TextFormat::GREEN . "/bw load " . TextFormat::YELLOW . "Load arena");
		$sender->sendMessage(TextFormat::GREEN . "/bw join " . TextFormat::YELLOW . "Join specific arena");
		$sender->sendMessage(TextFormat::GREEN . "/bw entity " . TextFormat::YELLOW . "spawn [game,wins,kills]");
		$sender->sendMessage(TextFormat::GREEN . "/bw entity " . TextFormat::YELLOW . "remover [removegame,removewins,removekills]");
	}


	public function generateSubCommandUsage(string $subCommand): string
	{
		$args = self::ARGUMENT_LIST[$subCommand];
		return "/bw " . $subCommand . " " . $args;
	}

}