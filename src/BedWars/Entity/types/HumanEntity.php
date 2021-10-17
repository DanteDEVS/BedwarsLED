<?php
declare(strict_types=1);

namespace BedWars\Entity\types;
use pocketmine\entity\Human;

class HumanEntity extends Human {

	public function getName(): string {
		return '';
	}
}