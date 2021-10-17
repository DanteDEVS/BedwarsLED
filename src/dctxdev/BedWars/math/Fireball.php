<?php

namespace vixikhd\BedWars\math;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\entity\projectile\{Projectile, Throwable};
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\{ItemFactory, Item};
use pocketmine\level\{Position, Level};
use pocketmine\level\particle\HugeExplodeParticle;
use pocketmine\event\entity\ExplosionPrimeEvent;
use pocketmine\level\Explosion;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\Player;

class Fireball extends Throwable {
    
    public const NETWORK_ID = self::SMALL_FIREBALL;

    public $width = 0.50;

    public $height = 0.50;
    
    protected $gravity = 0.0;
    
    protected $drag = 0;
    
    protected $life = 0;
    
    protected $damage = 2;

    public function __construct(Level $level, CompoundTag $nbt, Player $player = null) {
        parent::__construct($level, $nbt, $player);
    }

    public function getName(): string{
        return "Fireball";
    }
    
    public function entityBaseTick(int $tickDiff = 1) : bool {
        if ($this->closed){
            return false;
        }

        $hasUpdate = parent::entityBaseTick($tickDiff);
        $this->life++;
        if($this->life > 200){
            $this->flagForDespawn();
        }
        if ($this->getOwningEntity() == null){
            $this->flagForDespawn();
            return true;
        }

        return $hasUpdate;
    }
    
    public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_VOID){
			parent::attack($source);
		}
		if($source instanceof EntityDamageByEntityEvent){
		    $damager = $source->getDamager();
		    $this->setMotion($damager->getDirectionVector()->add(0, 0)->multiply(0.5));
		}
	}
	
	public function isCritical() : bool{
		return $this->getGenericFlag(self::DATA_FLAG_CRITICAL);
	}

	public function setCritical(bool $value = true) : void{
		$this->setGenericFlag(self::DATA_FLAG_CRITICAL, $value);
	}

	public function getResultDamage() : int{
		$base = parent::getResultDamage();
		if($this->isCritical()){
			return ($base + mt_rand(0, (int) ($base / 2) + 1));
		}else{
			return $base;
		}
	}
	
    public function onHitBlock(Block $blockHit, RayTraceResult $hitResult): void{
		parent::onHitBlock($blockHit, $hitResult);
		$this->doExplosionAnimation();
		$this->flagForDespawn();
	}
	
	protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult) : void{
		parent::onHitEntity($entityHit, $hitResult);
		$this->doExplosionAnimation();
		$this->flagForDespawn();
	}

	protected function doExplosionAnimation(): void{
	    $this->explode(); 
		$explosionSize = 2 * 2;
		$minX = (int) floor($this->x - $explosionSize - 1);
		$maxX = (int) ceil($this->x + $explosionSize + 1);
		$minY = (int) floor($this->y - $explosionSize - 1);
		$maxY = (int) ceil($this->y + $explosionSize + 1);
		$minZ = (int) floor($this->z - $explosionSize - 1);
		$maxZ = (int) ceil($this->z + $explosionSize + 1);

		$explosionBB = new AxisAlignedBB($minX, $minY, $minZ, $maxX, $maxY, $maxZ);

		$list = $this->level->getNearbyEntities($explosionBB, $this);
		foreach($list as $entity){
			$distance = $entity->distance($this->asVector3()) / $explosionSize;

			if($distance <= 2){
				$motion = $entity->subtract($this->asVector3())->normalize();
				$ev = new EntityDamageByChildEntityEvent($this->getOwningEntity(), $this, $entity, EntityDamageEvent::CAUSE_PROJECTILE, 3);
				$entity->attack($ev);
				$entity->setMotion($motion->multiply(2));
			}
		}
	}
	
	public function explode(): void{
		$ev = new ExplosionPrimeEvent($this, 2);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion(Position::fromObject($this->add(0, $this->height / 2, 0), $this->level), $ev->getForce(), $this);
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
	}
} 