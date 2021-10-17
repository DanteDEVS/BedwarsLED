<?php

namespace vixikhd\BedWars\math;

use pocketmine\entity\Entity;
use pocketmine\entity\{EffectInstance, Effect};
use pocketmine\event\entity\{EntityDamageByEntityEvent};
use pocketmine\event\entity\EntityDamageEvent;
use RPG\RPGdungeon\Entities\FireworksRocket;
use RPG\RPGdungeon\Items\Fireworks;
use pocketmine\level\{Position, Level};
use pocketmine\level\particle\HugeExplodeParticle;
use pocketmine\level\particle\DustParticle;
use pocketmine\event\entity\ExplosionPrimeEvent;
use pocketmine\level\Explosion;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\math\AxisAlignedBB;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\entity\Explosive; 
use pocketmine\level\utils\SubChunkIteratorManager;

class Dinamite extends Entity implements Explosive {

    public const NETWORK_ID = self::TNT;
    
    private $rays = 16;
    
	public $size = 4;
	public $affectedBlocks = [];
	
	public $stepLen = 0.3;

	private $subChunkHandler; 

	public $width = 0.98;
	public $height = 0.98;

	protected $baseOffset = 0.49;

	protected $gravity = 0.04;
	protected $drag = 0.0;
    
    protected $life = 0;
    protected $delay = 0;
    public $type = 1;
    public $owner;

    public function __construct(Level $level, CompoundTag $nbt) {
        parent::__construct($level, $nbt);
        $this->subChunkHandler = new SubChunkIteratorManager($this->level, false); 
    }
    
    public function initEntity(): void {
        parent::initEntity();
        //$this->getDataPropertyManager()->setFloat(self::DATA_BOUNDING_BOX_HEIGHT, 1);
        $this->setGenericFlag(self::DATA_FLAG_IGNITED, true);
		$this->propertyManager->setInt(self::DATA_FUSE_LENGTH, 80);
        $this->addSound($this, 'random.fuse', 1, false);
        $this->setNameTagVisible(true);
        $this->setNameTagAlwaysVisible(true);
    }
    
    public function canCollideWith(Entity $entity) : bool{
		return false;
	}
	
    public function getOwner() {
        return Server::getInstance()->getPlayer($this->owner);
    }

    public function getName(): string{
        return "TNT";
    }
    
    public function entityBaseTick(int $tickDiff = 1) : bool {
        if ($this->closed){
            return false;
        }
        if(80 % 5 === 0){
			$this->propertyManager->setInt(self::DATA_FUSE_LENGTH, 80);
		}
        $time = null;
        if($this->type == 1){
            $time = 20;
        } else {
            $time = 80;
        }
        $hasUpdate = parent::entityBaseTick($tickDiff);
        $this->life++;
        if($this->type == 1){
            $this->setNameTag("§dKnockback TNT");
            $this->getLevel()->addParticle(new DustParticle($this->asVector3(), 255, 0, 255, 1));
        }
        if($this->type == 2){
            $this->setNameTag("§aPoison TNT");
            $this->getLevel()->addParticle(new DustParticle($this->asVector3(), 0, 255, 255, 1));
        }
        if($this->type == 3){
            $this->setNameTag("§bFrozen TNT");
            $this->getLevel()->addParticle(new DustParticle($this->asVector3(), 0, 0, 255, 1));
        }
        if($this->type == 4){
        }
        if($this->life > $time){
            if($this->type == 1){
                $this->setNameTag("§dKnockback TNT");
                $this->KnockExplode();
            }
            if($this->type == 2){
                $this->setNameTag("§aPoison TNT");
                $this->PoisonExplode();
            }
            if($this->type == 3){
                $this->setNameTag("§bFrozen TNT");
                $this->FrozenExplode();
            }
            if($this->type == 4){
                $this->explode();
            }
        }
        if (!$this->getOwner() instanceof Player){
            $this->flagForDespawn();
            return true;
        }

        return $hasUpdate;
    }
	
    public function attack(EntityDamageEvent $source) : void{
        $source->setCancelled();
    }
	
	protected function KnockExplode(): void{
		$entity = $this->getOwner();
		if($entity instanceof Player){
		    if($entity->distance($this->asVector3()) <= 3){
		        $motion = $entity->subtract($this->asVector3())->normalize();
		        if($entity !== null){
		            $entity->setMotion($motion->multiply(1.7));
		            $ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_ENTITY_EXPLOSION, 1);
		            $entity->attack($ev);
		        }
		    }
		}
		$this->addSound($this, 'random.explode', 1, false);
		$this->level->addParticle(new HugeExplodeParticle($this->asVector3()));
		$this->flagForDespawn();
	}
	
	protected function PoisonExplode(): void{
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

			if($distance <= 4){
			    if($entity instanceof Player){
			    $ev = new EntityDamageByEntityEvent($this->getOwner(), $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 6);
				$entity->attack($ev);
				$eff = new EffectInstance(Effect::getEffect(Effect::POISON), 60, 1);
                $eff->setVisible(false);
                $entity->addEffect($eff);
			    }
			}
		}
		$this->addSound($this, 'random.explode', 1, false);
		$this->level->addParticle(new HugeExplodeParticle($this->asVector3()));
		$this->flagForDespawn(); 
	}
	
	protected function FrozenExplode(): void{
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

			if($distance <= 3){
			    if($entity instanceof Player){
				$eff = new EffectInstance(Effect::getEffect(Effect::SLOWNESS), 80, 1);
                $eff->setVisible(false);
                $entity->addEffect($eff);
			    }
			}
		}
		$this->flagForDespawn(); 
	}

	public function explode(): void{
	    $ev = new ExplosionPrimeEvent($this, 4);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion(Position::fromObject($this->add(0, $this->height / 2, 0), $this->level), $ev->getForce(), $this);
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
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

			if($distance <= 5){
			    if($entity instanceof Player){
				$motion = $entity->subtract($this->asVector3())->normalize();

				$impact = (1 - $distance) * ($exposure = 2);

				$damage = (int) ((($impact * $impact + $impact) / 2) * 1 * $explosionSize + 0.5);

				$ev = new EntityDamageByEntityEvent($this->getOwner(), $entity, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 6);
				$entity->attack($ev);
				$entity->setMotion($motion->multiply(1.5));
			    }
			}
		}
		$this->flagForDespawn();
	}
	
	public function addSound($player, string $sound = '', float $pitch = 1, bool $type = true){
        $pk = new PlaySoundPacket();
		$pk->x = $player->getX();
		$pk->y = $player->getY();
		$pk->z = $player->getZ();
		$pk->volume = 2;
		$pk->pitch = $pitch;
		$pk->soundName = $sound;
		if($type){
		    $player->dataPacket($pk);
		} else {
			Server::getInstance()->broadcastPacket($player->getLevel()->getPlayers(), $pk);
		}
    }
}
