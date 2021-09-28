<?php
declare(strict_types=1);

namespace BedWars\Tasks;

use BedWars\{Entity\types\HumanEntity, Entity\types\TopsEntity, Entity\types\TopsEntitykill};
use BedWars\BedWars;
use BedWars\game\GameListener;
use pocketmine\{entity\Effect, entity\EffectInstance, scheduler\Task, Server, utils\TextFormat as Color};

class EntityUpdate extends Task
{

	public function onRun(int $currentTick)
	{
		foreach (Server::getInstance()->getDefaultLevel()->getEntities() as $entity) {
			if ($entity instanceof HumanEntity) {
				$entity->addEffect(new EffectInstance(Effect::getEffect(Effect::FIRE_RESISTANCE), 999));
				$entity->setNameTag(self::setNameb());
				$entity->setNameTagAlwaysVisible(true);
				$entity->setScale(1.2);
			}
			if ($entity instanceof TopsEntitykill) {
				$entity->setNameTag(self::Topkills());
				$entity->setNameTagAlwaysVisible(true);
			} else if ($entity instanceof TopsEntity) {
				$entity->setNameTag(self::Topwins());
				$entity->setNameTagAlwaysVisible(true);
			}

		}
	}

	public static function setNameb(): string
	{
		$comment = Color::GREEN . '§l' . Color::YELLOW . 'CLICK TO PLAY' . Color::GRAY . '' . "\n";
		$title = Color::BOLD . '§bBEDWARS' . "\n";
		$subtitle = Color::GREEN . self::getTotalPlayersInGame() . ' §eOnline' . "\n";
		return $comment . $title . $subtitle;
	}

	public static function getTotalPlayersInGame(): int
	{
		$totalPlayers = [];
		foreach (GameListener::getAllArenas() as $arena) {
			if (Server::getInstance()->getLevelByName($arena) !== null) {
				foreach (Server::getInstance()->getLevelByName($arena)->getPlayers() as $player) {
					$totalPlayers[] = $player;
				}
			}
		}
		return count($totalPlayers);
	}

	public static function Topkills(): string
	{
		$kills = BedWars::getConfigs('kills');
		$tops = [];
		$title = "§l§bLEADERBOARD\n§eBedwars kills" . Color::RESET . "\n";
		foreach ($kills->getAll() as $key => $top) {
			array_push($tops, $top);
		}
		natsort($tops);
		$player = array_reverse($tops);
		if (max($tops) != null) {
			$top1 = array_search(max($tops), $kills->getAll());
			$subtitle1 = Color::GOLD . '#1 ' . Color::AQUA . $top1 . Color::GRAY . ' - ' . Color::WHITE . max($tops) . Color::GOLD . ' kills' . "\n";
		} else {
			$subtitle1 = '';
		}
		if ($player[1] != null) {
			$top2 = array_search($player[1], $kills->getAll());
			$subtitle2 = Color::GREEN . '#2 ' . Color::AQUA . $top2 . Color::GRAY . ' - ' . Color::WHITE . $player[1] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle2 = '';
		}
		if ($player[2] != null) {
			$top3 = array_search($player[2], $kills->getAll());
			$subtitle3 = Color::GREEN . '#3 ' . Color::AQUA . $top3 . Color::GRAY . ' - ' . Color::WHITE . $player[2] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle3 = '';
		}
		if ($player[3] != null) {
			$top4 = array_search($player[3], $kills->getAll());
			$subtitle4 = Color::GREEN . '#4 ' . Color::AQUA . $top4 . Color::GRAY . ' - ' . Color::WHITE . $player[3] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle4 = '';
		}
		if ($player[4] != null) {
			$top5 = array_search($player[4], $kills->getAll());
			$subtitle5 = Color::GREEN . '#5 ' . Color::AQUA . $top5 . Color::GRAY . ' - ' . Color::WHITE . $player[4] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle5 = '';
		}
		if ($player[5] != null) {
			$top6 = array_search($player[5], $kills->getAll());
			$subtitle6 = Color::GREEN . '#6 ' . Color::AQUA . $top6 . Color::GRAY . ' - ' . Color::WHITE . $player[5] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle6 = '';
		}
		if ($player[6] != null) {
			$top7 = array_search($player[6], $kills->getAll());
			$subtitle7 = Color::GREEN . '#7 ' . Color::AQUA . $top7 . Color::GRAY . ' - ' . Color::WHITE . $player[6] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle7 = '';
		}
		if ($player[7] != null) {
			$top8 = array_search($player[7], $kills->getAll());
			$subtitle8 = Color::GREEN . '#8 ' . Color::AQUA . $top8 . Color::GRAY . ' - ' . Color::WHITE . $player[7] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle8 = '';
		}
		if ($player[8] != null) {
			$top9 = array_search($player[8], $kills->getAll());
			$subtitle9 = Color::GREEN . '#9 ' . Color::AQUA . $top9 . Color::GRAY . ' - ' . Color::WHITE . $player[8] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle9 = '';
		}
		if ($player[9] != null) {
			$top10 = array_search($player[9], $kills->getAll());
			$subtitle10 = Color::GREEN . '#10 ' . Color::AQUA . $top10 . Color::GRAY . ' - ' . Color::WHITE . $player[9] . Color::GREEN . ' kills' . "\n";
		} else {
			$subtitle10 = '';
		}
		return $title . $subtitle1 . $subtitle2 . $subtitle3 . $subtitle4 . $subtitle5 . $subtitle6 . $subtitle7 . $subtitle8 . $subtitle9 . $subtitle10;
	}

	public static function Topwins(): string
	{
		$data = BedWars::getConfigs('wins');
		$tops = $data->getAll();
		arsort($tops);
		$tops = array_slice($tops, 0, 10);
		$counter = 0;
		$text = "§l§bLEADERBOARD\n§eBedwars Wins";
		foreach ($tops as $key => $value) {
			$counter++;
			$text .= "\n" . Color::GREEN . '#' . $counter . " " . Color::AQUA . $key . Color::GRAY . ' - ' . Color::WHITE . $value . Color::GREEN . ' Wins';
		}
		return $text;
	}
}