<?php

namespace AssassinGhost\KnockBack;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\utils\Config;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\math\Vector3;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\WorldManager;
use pocketmine\world\Position;
use AssassinGhost\KnockBack\arena\ArenaManager;
use AssassinGhost\KnockBack\manager\GameManager;
use AssassinGhost\KnockBack\entity\KnockBackNPC;
use AssassinGhost\KnockBack\form\KnockBackSelectForm;
use pocketmine\item\VanillaItems;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\scheduler\ClosureTask;
use AssassinGhost\KnockBack\task\BlockTask;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\SignText;
use pocketmine\entity\Human;
use pocketmine\entity\Skin;

class Main extends PluginBase implements Listener {

    private float $horizontal;
    private float $vertical;
    private float $extra;
    private array $messages = [];
    private StatsManager $stats;
    private ArenaManager $arenaManager;
    private GameManager $gameManager;
    /** @var array<string, array> */
    private array $arenaCreation = [];
    /** @var array<string, array> */
    private array $arenaEdit = [];
    /** @var array<string, bool> */
    private array $safeZonePlayers = [];
    /** @var array<string, \pocketmine\world\Position> */
    private array $mainSpawnPositions = [];
    /** @var \pocketmine\world\Position|null */
    private ?\pocketmine\world\Position $defaultMainSpawn = null;
    /** @var array<string, int> */
    private array $placedBlocks = [];
    /** @var array<string, Position> */
    private array $npcPositions = [];
    /** @var array<string, Position> */
    private array $signPositions = [];
    /** @var array<string, bool> */
    private array $adminMode = [];
    /** @var array<string, int> */
    private array $adminModeStep = [];

    public function onEnable() : void {
        @mkdir($this->getDataFolder());
        @mkdir($this->getDataFolder() . "npcs/");
        @mkdir($this->getDataFolder() . "signs/");
        
        $this->saveResource("config.yml");
        $this->saveResource("messages.yml");

        $this->reloadValues();
        $this->loadMainSpawn();
        $this->loadNPCs();
        $this->loadSigns();

        $this->stats = new StatsManager($this);
        $this->arenaManager = new ArenaManager($this);
        $this->gameManager = new GameManager($this);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Scoreboard cada 2 segundos
        $this->getScheduler()->scheduleRepeatingTask(new ScoreTask($this), 40);

        $this->getLogger()->info("§aKnockBack Plus v2.0.0 cargado por AssassinGhost");
    }

    public function reloadValues() : void {
        $cfg = new Config($this->getDataFolder() . "config.yml", Config::YAML, []);
        $this->horizontal = (float) ($cfg->getNested("knockback.horizontal", 0.4));
        $this->vertical   = (float) ($cfg->getNested("knockback.vertical", 0.4));
        $this->extra      = (float) ($cfg->getNested("knockback.extra", 0.4));

        $msg = new Config($this->getDataFolder() . "messages.yml", Config::YAML, []);
        $this->messages = $msg->getAll();
    }

    private function loadMainSpawn() : void {
        $cfg = new Config($this->getDataFolder() . "spawns.yml", Config::YAML, []);
        $spawns = $cfg->getAll();
        
        foreach ($spawns as $playerName => $spawnData) {
            if (isset($spawnData["world"], $spawnData["x"], $spawnData["y"], $spawnData["z"])) {
                $world = $this->getServer()->getWorldManager()->getWorldByName($spawnData["world"]);
                if ($world !== null) {
                    $this->mainSpawnPositions[$playerName] = new \pocketmine\world\Position(
                        $spawnData["x"],
                        $spawnData["y"],
                        $spawnData["z"],
                        $world
                    );
                }
            }
        }
        
        // Establecer spawn principal por defecto (spawn del servidor)
        $defaultWorld = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($defaultWorld !== null) {
            $this->defaultMainSpawn = $defaultWorld->getSafeSpawn();
        }
    }

    private function loadNPCs() : void {
        $cfg = new Config($this->getDataFolder() . "npcs/npcs.yml", Config::YAML, []);
        $npcs = $cfg->getAll();
        
        foreach ($npcs as $npcId => $npcData) {
            if (isset($npcData["world"], $npcData["x"], $npcData["y"], $npcData["z"])) {
                $world = $this->getServer()->getWorldManager()->getWorldByName($npcData["world"]);
                if ($world !== null) {
                    $this->npcPositions[$npcId] = new Position(
                        $npcData["x"],
                        $npcData["y"],
                        $npcData["z"],
                        $world
                    );
                    // Spawn del NPC
                    $this->spawnNPC($this->npcPositions[$npcId], $npcId);
                }
            }
        }
    }

    private function loadSigns() : void {
        $cfg = new Config($this->getDataFolder() . "signs/signs.yml", Config::YAML, []);
        $signs = $cfg->getAll();
        
        foreach ($signs as $signId => $signData) {
            if (isset($signData["world"], $signData["x"], $signData["y"], $signData["z"], $signData["arena"])) {
                $world = $this->getServer()->getWorldManager()->getWorldByName($signData["world"]);
                if ($world !== null) {
                    $this->signPositions[$signId] = new Position(
                        $signData["x"],
                        $signData["y"],
                        $signData["z"],
                        $world
                    );
                }
            }
        }
    }

    private function spawnNPC(Position $position, string $npcId) : void {
        $nbt = Human::createBaseNBT($position);
        
        // Crear skin básico para el NPC
        $skinData = str_repeat("\x00", 8192); // Skin vacío básico
        $capeData = str_repeat("\x00", 8192); // Cape vacío
        $skin = new Skin("knockback_npc", $skinData, $capeData, "geometry.humanoid.custom");
        
        $npc = new Human($position->getWorld(), $nbt, $skin);
        $npc->setNameTag("§b§lKnockBack\n§7Dale click para jugar");
        $npc->setNameTagAlwaysVisible(true);
        $npc->setNameTagVisible(true);
        $npc->spawnToAll();
        
        // Guardar referencia del NPC
        $this->npcPositions[$npcId] = $position;
    }

    private function saveMainSpawn(Player $player, \pocketmine\world\Position $position) : void {
        $cfg = new Config($this->getDataFolder() . "spawns.yml", Config::YAML, []);
        $spawns = $cfg->getAll();
        
        $spawns[$player->getName()] = [
            "world" => $position->getWorld()->getFolderName(),
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ()
        ];
        
        $cfg->setAll($spawns);
        $cfg->save();
        
        $this->mainSpawnPositions[$player->getName()] = $position;
    }

    private function getPlayerMainSpawn(Player $player) : \pocketmine\world\Position {
        if (isset($this->mainSpawnPositions[$player->getName()])) {
            return $this->mainSpawnPositions[$player->getName()];
        }
        return $this->defaultMainSpawn ?? $player->getWorld()->getSafeSpawn();
    }

    public function onDisable() : void {
        if(isset($this->stats)) {
            $this->stats->saveAll();
        }
        if(isset($this->arenaManager)) {
            $this->arenaManager->saveArenas();
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void {
        $this->stats->initPlayer($event->getPlayer());
        
        $player = $event->getPlayer();
        
        // Asegurar que aparezca en el spawn principal, no en arena
        if ($this->arenaManager->isInArena($player)) {
            $this->arenaManager->removePlayerFromArena($player);
        }
        
        // Limpiar efectos y inventario por si trae cosas de arena
        $this->removeKnockBackKit($player);
        
        // Guardar la posición de spawn principal cuando el jugador se conecta
        if (!isset($this->mainSpawnPositions[$player->getName()])) {
            $this->saveMainSpawn($player, $player->getPosition());
        }
        
        // Asegurar que esté en el spawn principal
        $mainSpawn = $this->getPlayerMainSpawn($player);
        if ($player->getPosition()->distance($mainSpawn) > 5) { // Si está lejos del spawn
            $player->teleport($mainSpawn);
        }
    }

    public function onQuit(PlayerQuitEvent $event) : void {
        $this->stats->savePlayer($event->getPlayer());
        $player = $event->getPlayer();
        
        // Si estaba en arena, removerlo para que no aparezca ahí al reconectarse
        if ($this->arenaManager->isInArena($player)) {
            $this->gameManager->removePlayerFromGame($player);
        }
        
        // Limpiar datos del jugador
        unset($this->safeZonePlayers[$player->getName()]);
        if (isset($this->arenaCreation[$player->getName()])) {
            unset($this->arenaCreation[$player->getName()]);
        }
        if (isset($this->arenaEdit[$player->getName()])) {
            unset($this->arenaEdit[$player->getName()]);
        }
        if (isset($this->adminMode[$player->getName()])) {
            unset($this->adminMode[$player->getName()]);
        }
        if (isset($this->adminModeStep[$player->getName()])) {
            unset($this->adminModeStep[$player->getName()]);
        }
    }
    
    public function onDamage(EntityDamageEvent $event) : void {
        $victim = $event->getEntity();
        if (!$victim instanceof Player) {
            return;
        }
        
        $cause = $event->getCause();
        
        // Si el jugador está en zona segura, cancelar todo el daño
        if (isset($this->safeZonePlayers[$victim->getName()])) {
            $event->cancel();
            return;
        }
        
        if ($this->arenaManager->isInArena($victim)) {
            // En la arena, solo cancelar daño por caída, ahogamiento y vacío
            if ($cause === EntityDamageEvent::CAUSE_FALL || 
                $cause === EntityDamageEvent::CAUSE_DROWNING || 
                $cause === EntityDamageEvent::CAUSE_VOID) {
                $event->cancel();
                
                // Si hubiera muerto por caída o vacío, teleportar al spawn de la arena
                if ($victim->getHealth() - $event->getFinalDamage() <= 0) {
                    $arena = $this->arenaManager->getArenaOf($victim);
                    if ($arena !== null) {
                        $this->arenaManager->teleportToSpawn($victim, $arena);
                        $victim->setHealth($victim->getMaxHealth());
                        $this->safeZonePlayers[$victim->getName()] = true;
                        $victim->sendMessage("§aHas sido teleportado al spawn seguro. Bájate para comenzar a pelear.");
                    }
                }
            }
        }
        
        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Player) {
                // Si alguno está en zona segura, cancelar el daño
                if (isset($this->safeZonePlayers[$victim->getName()]) || 
                    isset($this->safeZonePlayers[$damager->getName()])) {
                    $event->cancel();
                    return;
                }
    
                $arena = $this->arenaManager->getArenaOf($damager);
                if ($arena !== null) {
                    $h = $arena->getHorizontal();
                    $v = $arena->getVertical();
                    $e = $arena->getExtra();
                } else {
                    $h = $this->horizontal;
                    $v = $this->vertical;
                    $e = $this->extra;
                }
    
                $vx = $damager->getPosition()->x - $victim->getPosition()->x;
                $vz = $damager->getPosition()->z - $victim->getPosition()->z;
                $length = sqrt($vx * $vx + $vz * $vz);
                if ($length == 0) $length = 1;
    
                $motionX = ($vx / $length) * $h;
                $motionZ = ($vz / $length) * $h;
                $motionY = $v;
    
                $victim->setMotion(new Vector3($motionX * $e, $motionY * $e, $motionZ * $e));
            }
        }
    }

    public function onDeath(PlayerDeathEvent $event) : void {
        $player = $event->getPlayer();
        $arena = $this->arenaManager->getArenaOf($player);
        if($arena !== null) {
            $event->setDrops([]);
            $event->setDeathMessage("");
            
            // Teleportar al spawn de la arena y activar zona segura
            $this->arenaManager->teleportToSpawn($player, $arena);
            $this->safeZonePlayers[$player->getName()] = true;
            
            // Dar efectos de nuevo (por si los perdió al morir)
            $this->giveKnockBackKit($player);
            
            $player->sendMessage("§aHas muerto. Estás en zona segura, bájate para continuar peleando.");
        }
    }
    
    public function onMove(PlayerMoveEvent $event) : void {
        $player = $event->getPlayer();
        
        // Mostrar ayuda visual en modo admin
        if (isset($this->adminMode[$player->getName()]) && $this->adminMode[$player->getName()]) {
            $this->showAdminModeHelp($player);
        }
        
        // Solo procesar si el jugador está en zona segura
        if(isset($this->safeZonePlayers[$player->getName()])) {
            $arena = $this->arenaManager->getArenaOf($player);
            if($arena !== null) {
                $safeZoneY = $arena->getSpawnSafeZoneY();
                $playerY = $player->getPosition()->getY();
                
                // Si el jugador baja por debajo de la zona segura, quitarlo de zona segura
                if($playerY < $safeZoneY) {
                    unset($this->safeZonePlayers[$player->getName()]);
                    $player->sendMessage("§cHas salido de la zona segura. §ePuedes recibir y hacer daño ahora.");
                }
            } else {
                // Si no está en arena, quitar de zona segura
                unset($this->safeZonePlayers[$player->getName()]);
            }
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event) : void {
        $player = $event->getPlayer();
        if ($this->arenaManager->isInArena($player)) {
            // Verificar que el bloque es arenisca
            if ($event->getBlock()->getTypeId() === VanillaBlocks::SANDSTONE()->getTypeId()) {
                $block = $event->getBlock();
                $pos = $block->getPosition();
                $blockHash = "{$pos->getX()}:{$pos->getY()}:{$pos->getZ()}";
                
                // Agregar el bloque al sistema de eliminación automática
                $this->placedBlocks[$blockHash] = 5; // 5 segundos de vida
                
                // Si no hay una tarea ejecutándose, crear una nueva
                if (count($this->placedBlocks) === 1) {
                    $this->getScheduler()->scheduleRepeatingTask(new BlockTask(
                        $player->getWorld(),
                        $this->placedBlocks,
                        5 // tiempo máximo
                    ), 20); // 20 ticks = 1 segundo
                }
            }
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) : void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        
        // Verificar si es un cartel
        if ($block instanceof \pocketmine\block\BaseSign) {
            $signText = $block->getText();
            if ($signText->getLine(0) === "§b[KnockBack]") {
                $arenaName = $signText->getLine(1);
                if ($arenaName !== "" && isset($this->arenaManager->getArenas()[$arenaName])) {
                    $this->gameManager->joinArena($player, $arenaName, false); // false = arena específica
                }
            }
        }
        
        // Verificar placas de presión doradas
        if ($block->getTypeId() === VanillaBlocks::WEIGHTED_PRESSURE_PLATE_HEAVY()->getTypeId() && 
            $this->arenaManager->isInArena($player)) {
            // Super salto
            $player->setMotion(new Vector3(0, 2.5, 0)); // Impulso hacia arriba
            $player->sendMessage("§6¡SUPER SALTO!");
        }
    }

    public function onSignChange(SignChangeEvent $event) : void {
        $player = $event->getPlayer();
        $lines = $event->getNewText()->getLines();
        
        if ($lines[0] === "[kb]" || $lines[0] === "[knockback]") {
            if (!$player->hasPermission("knockback.createsign")) {
                $player->sendMessage("§cNo tienes permiso para crear carteles de KnockBack.");
                $event->cancel();
                return;
            }
            
            if (!isset($lines[1]) || $lines[1] === "") {
                $player->sendMessage("§cLínea 2: Nombre de la arena");
                $event->cancel();
                return;
            }
            
            $arenaName = $lines[1];
            if (!isset($this->arenaManager->getArenas()[$arenaName])) {
                $player->sendMessage("§cLa arena §e{$arenaName}§c no existe.");
                $event->cancel();
                return;
            }
            
            // Crear el cartel
            $newText = new SignText([
                "§b[KnockBack]",
                $arenaName,
                "§aClick para unirse",
                "§70/20 jugadores"
            ]);
            $event->setNewText($newText);
            
            // Guardar el cartel
            $this->saveSign($event->getBlock()->getPosition(), $arenaName);
            $player->sendMessage("§aCartel de KnockBack creado para la arena §e{$arenaName}");
        }
    }

    private function saveSign(Position $position, string $arenaName) : void {
        $cfg = new Config($this->getDataFolder() . "signs/signs.yml", Config::YAML, []);
        $signs = $cfg->getAll();
        
        $signId = uniqid();
        $signs[$signId] = [
            "world" => $position->getWorld()->getFolderName(),
            "x" => $position->getX(),
            "y" => $position->getY(),
            "z" => $position->getZ(),
            "arena" => $arenaName
        ];
        
        $cfg->setAll($signs);
        $cfg->save();
        
        $this->signPositions[$signId] = $position;
    }

    public function updateSigns() : void {
        foreach ($this->signPositions as $signId => $position) {
            $cfg = new Config($this->getDataFolder() . "signs/signs.yml", Config::YAML, []);
            $signData = $cfg->get($signId);
            
            if ($signData && isset($signData["arena"])) {
                $arenaName = $signData["arena"];
                $arena = $this->arenaManager->getArena($arenaName);
                
                if ($arena) {
                    $playerCount = $this->gameManager->getPlayersInArena($arenaName);
                    $maxPlayers = $arena->getMaxPlayers();
                    
                    $world = $position->getWorld();
                    $block = $world->getBlock($position);
                    
                    if ($block instanceof \pocketmine\block\BaseSign) {
                        $status = $playerCount >= $maxPlayers ? "§cLLENO" : "§aDisponible";
                        $countColor = $playerCount >= $maxPlayers ? "§c" : "§7";
                        
                        $newText = new SignText([
                            "§b[KnockBack]",
                            $arenaName,
                            $status,
                            "{$countColor}{$playerCount}/{$maxPlayers} jugadores"
                        ]);
                        
                        $block->setText($newText);
                        $world->setBlock($position, $block);
                    }
                }
            }
        }
    }

    public function getKills(Player $player): int{
        return $this->stats->getKills($player);
    }

    public function getDeaths(Player $player): int{
        return $this->stats->getDeaths($player);
    }

    public function getHorizontal(): float { return $this->horizontal; }
    public function getVertical(): float { return $this->vertical; }
    public function getExtra(): float { return $this->extra; }

    public function getMessage(string $key): string {
        return $this->messages[$key] ?? "";
    }

    public function getArenaManager(): ArenaManager {
        return $this->arenaManager;
    }

    public function getGameManager(): GameManager {
        return $this->gameManager;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() === "knockback") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("Usa este comando dentro del juego.");
                return true;
            }

            if (!isset($args[0])) {
                $h = $this->getHorizontal();
                $v = $this->getVertical();
                $e = $this->getExtra();
                $msg = str_replace(["{h}","{v}","{e}"], [(string)$h,(string)$v,(string)$e], $this->getMessage("info"));
                $sender->sendMessage($this->getMessage("prefix") . $msg);

                $sender->sendMessage("§e==== KnockBack Plus Help ====");
                $sender->sendMessage("§e/kb create <nombre> §7- Crear una nueva arena");
                $sender->sendMessage("§e/kb pos1 §7- Guardar el primer spawn");
                $sender->sendMessage("§e/kb pos2 <y> §7- Guardar el segundo spawn y la zona segura");
                $sender->sendMessage("§e/kb save §7- Guardar arena y volver al spawn principal");
                $sender->sendMessage("§e/kb edit <nombre> §7- Editar una arena existente");
                $sender->sendMessage("§e/kb setlimit <arena> <min> <max> §7- Configurar límites");
                $sender->sendMessage("§e/kb createnpc §7- Crear NPC para unirse aleatorio");
                $sender->sendMessage("§e/kb join <nombre> §7- Unirse a una arena");
                $sender->sendMessage("§e/kb leave §7- Salir de la arena actual");
                $sender->sendMessage("§e/kb arenas §7- Ver estado de todas las arenas");
                $sender->sendMessage("§e/kb coords §7- Ver tu posición actual (para admins)");
                $sender->sendMessage("§e/kb menu §7- Abrir el menú de configuración");
                $sender->sendMessage("§e/kb reload §7- Recargar la configuración");
                $sender->sendMessage("§7Carteles: Pon §e[kb] §7en línea 1 y nombre de arena en línea 2");
                return true;
            }

            switch (strtolower($args[0])) {
                case "reload":
                    if (!$sender->hasPermission("knockback.reload")) {
                        $sender->sendMessage("§cNo tienes permiso para recargar la configuración.");
                        return true;
                    }
                    $this->reloadValues();
                    $this->arenaManager->reloadArenaConfigs();
                    $sender->sendMessage($this->getMessage("prefix") . $this->getMessage("reloaded"));
                    $sender->sendMessage("§aConfiguración de arenas recargada también.");
                    break;

                case "menu":
                    if (!$sender->hasPermission("knockback.menu")) {
                        $sender->sendMessage("§cNo tienes permiso para abrir el menú.");
                        return true;
                    }
                    $worlds = [];
                    foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
                        $worlds[] = $world->getFolderName();
                    }
                    $sender->sendForm(new KnockBackSelectForm($worlds, $this));
                    break;

                case "create":
                    if (!$sender->hasPermission("knockback.create")) {
                        $sender->sendMessage("§cNo tienes permiso para crear arenas.");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $sender->sendMessage("§cUso: /kb create <nombre>");
                        return true;
                    }
                    
                    // Verificar si ya está en modo admin
                    if (isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cYa estás en modo administrador. Usa §e/kb cancel §cpara cancelar.");
                        return true;
                    }
                    
                    $this->saveMainSpawn($sender, $sender->getPosition());
                    
                    $name = $args[1];
                    $worldManager = $this->getServer()->getWorldManager();
                    if (!$worldManager->isWorldLoaded($name)) {
                        $this->getServer()->getLogger()->info("§aEl mundo §e{$name}§a no existe, creando...");
                        $worldManager->generateWorld($name, new WorldCreationOptions());
                        $worldManager->loadWorld($name);
                        $sender->sendMessage("§aMundo §e{$name}§a creado. Teletransportando...");
                    } else {
                        $sender->sendMessage("§aEl mundo §e{$name}§a ya existe. Teletransportando...");
                    }
                    
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sender, $name) {
                        $world = $this->getServer()->getWorldManager()->getWorldByName($name);
                        if ($world !== null) {
                            $sender->teleport($world->getSafeSpawn());
                            $this->arenaCreation[$sender->getName()] = ["name" => $name, "world" => $name];
                            $this->startAdminMode($sender, $name, false);
                        } else {
                            $sender->sendMessage("§cError al teletransportar.");
                        }
                    }), 20);
                    break;

                case "pos1":
                    if (!$sender->hasPermission("knockback.create")) {
                        $sender->sendMessage("§cNo tienes permiso para crear arenas.");
                        return true;
                    }
                    
                    // Verificar modo admin
                    if (!isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cDebes usar §e/kb create <nombre> §co §e/kb edit <nombre> §cprimero.");
                        return true;
                    }
                    
                    if ($this->adminModeStep[$sender->getName()] !== 1) {
                        $sender->sendMessage("§cDebes completar los pasos en orden.");
                        return true;
                    }
                    
                    if (!isset($this->arenaCreation[$sender->getName()]) && !isset($this->arenaEdit[$sender->getName()])) {
                        $sender->sendMessage("§cError: No hay arena en configuración.");
                        return true;
                    }
                    
                    if (!isset($this->arenaCreation[$sender->getName()])) {
                        $this->arenaCreation[$sender->getName()] = $this->arenaEdit[$sender->getName()];
                    }
                    
                    $this->arenaCreation[$sender->getName()]["spawn1"] = $sender->getPosition();
                    $this->adminModeStep[$sender->getName()] = 2;
                    
                    // Mensaje visual del paso 2
                    $sender->sendTitle("§a§lPASO 1 COMPLETADO", "§eSpawn 1 guardado", 5, 30, 10);
                    $sender->sendMessage("§e========================================");
                    $sender->sendMessage("§a✓ Spawn 1 guardado en tu posición actual");
                    $sender->sendMessage("§ePaso 2/3: §fElige la posición del SEGUNDO SPAWN");
                    $sender->sendMessage("§7Muévete al lugar donde quieres el spawn 2 y usa:");
                    $sender->sendMessage("§e/kb pos2 <alturaY>");
                    $sender->sendMessage("§7Ejemplo: §e/kb pos2 50");
                    $sender->sendMessage("§e========================================");
                    break;

                case "pos2":
                    if (!$sender->hasPermission("knockback.create")) {
                        $sender->sendMessage("§cNo tienes permiso para crear arenas.");
                        return true;
                    }
                    
                    // Verificar modo admin
                    if (!isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cDebes usar §e/kb create <nombre> §co §e/kb edit <nombre> §cprimero.");
                        return true;
                    }
                    
                    if ($this->adminModeStep[$sender->getName()] !== 2) {
                        $sender->sendMessage("§cDebes completar los pasos en orden. Usa §e/kb pos1 §cprimero.");
                        return true;
                    }
                    
                    if (!isset($args[1]) || !is_numeric($args[1])) {
                        $sender->sendMessage("§cUso: /kb pos2 <alturaY>");
                        $sender->sendMessage("§7La alturaY es el límite de la zona segura");
                        $sender->sendMessage("§eEjemplo: §f/kb pos2 " . round($sender->getPosition()->getY()));
                        return true;
                    }
                    
                    $this->arenaCreation[$sender->getName()]["spawn2"] = $sender->getPosition();
                    $this->arenaCreation[$sender->getName()]["safeZoneY"] = (int)$args[1];
                    $this->adminModeStep[$sender->getName()] = 3;
                    
                    // Mensaje visual del paso 3
                    $sender->sendTitle("§a§lPASO 2 COMPLETADO", "§eSpawn 2 y zona segura guardados", 5, 30, 10);
                    $sender->sendMessage("§e========================================");
                    $sender->sendMessage("§a✓ Spawn 2 guardado en tu posición actual");
                    $sender->sendMessage("§a✓ Zona segura configurada hasta Y: §e{$args[1]}");
                    $sender->sendMessage("§ePaso 3/3: §f¡LISTO PARA GUARDAR!");
                    $sender->sendMessage("§aUsa §e/kb save §apara finalizar la configuración");
                    $sender->sendMessage("§7O usa §c/kb cancel §7para cancelar");
                    $sender->sendMessage("§e========================================");
                    break;

                case "save":
                    if (!$sender->hasPermission("knockback.create")) {
                        $sender->sendMessage("§cNo tienes permiso para crear arenas.");
                        return true;
                    }
                    
                    // Verificar modo admin
                    if (!isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cNo estás en modo administrador.");
                        return true;
                    }
                    
                    if ($this->adminModeStep[$sender->getName()] !== 3) {
                        $sender->sendMessage("§cCompleta todos los pasos primero.");
                        return true;
                    }
                    
                    if (!isset($this->arenaCreation[$sender->getName()]) || 
                        !isset($this->arenaCreation[$sender->getName()]["spawn1"]) ||
                        !isset($this->arenaCreation[$sender->getName()]["spawn2"]) ||
                        !isset($this->arenaCreation[$sender->getName()]["safeZoneY"])) {
                        $sender->sendMessage("§cError: Datos incompletos.");
                        return true;
                    }
                    
                    $data = $this->arenaCreation[$sender->getName()];
                    $spawn1 = new Vector3($data["spawn1"]->getX(), $data["spawn1"]->getY(), $data["spawn1"]->getZ());
                    $spawn2 = new Vector3($data["spawn2"]->getX(), $data["spawn2"]->getY(), $data["spawn2"]->getZ());
                    $safeZoneY = $data["safeZoneY"];
                    
                    $this->arenaManager->createArena($data["name"], $data["world"], $spawn1, $spawn2, $safeZoneY, 2, 20);
                    
                    // Limpiar datos de creación
                    unset($this->arenaCreation[$sender->getName()]);
                    if (isset($this->arenaEdit[$sender->getName()])) {
                        unset($this->arenaEdit[$sender->getName()]);
                    }
                    
                    // Finalizar modo admin
                    $this->stopAdminMode($sender);
                    
                    // Mensajes finales
                    $sender->sendMessage("§e========================================");
                    $sender->sendMessage("§a§l¡ARENA CREADA EXITOSAMENTE!");
                    $sender->sendMessage("§e========================================");
                    $sender->sendMessage("§aNombre: §e{$data["name"]}");
                    $sender->sendMessage("§aMundo: §e{$data["world"]}");
                    $sender->sendMessage("§aLímites: §e2-20 jugadores");
                    $sender->sendMessage("§aZona segura: §eY {$safeZoneY}");
                    $sender->sendMessage("§aTeleportándote de vuelta al spawn principal...");
                    $sender->sendMessage("§e========================================");
                    
                    // Teleportar de vuelta al spawn principal
                    $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($sender, $data) {
                        $mainSpawn = $this->getPlayerMainSpawn($sender);
                        $sender->teleport($mainSpawn);
                        $sender->sendMessage("§a§lComandos útiles:");
                        $sender->sendMessage("§e/kb join {$data["name"]} §7- Probar la arena");
                        $sender->sendMessage("§e/kb createnpc §7- Crear NPC");
                        $sender->sendMessage("§7Carteles: Pon §e[kb] §7en línea 1, §e{$data["name"]} §7en línea 2");
                    }), 40);
                    break;

                case "cancel":
                    if (!isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cNo estás en modo administrador.");
                        return true;
                    }
                    
                    $this->cancelAdminMode($sender);
                    break;

                case "edit":
                    if (!$sender->hasPermission("knockback.edit")) {
                        $sender->sendMessage("§cNo tienes permiso para editar arenas.");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $sender->sendMessage("§cUso: /kb edit <nombre>");
                        return true;
                    }
                    
                    // Verificar si ya está en modo admin
                    if (isset($this->adminMode[$sender->getName()])) {
                        $sender->sendMessage("§cYa estás en modo administrador. Usa §e/kb cancel §cpara cancelar.");
                        return true;
                    }
                    
                    $arenaName = $args[1];
                    $arena = $this->arenaManager->getArena($arenaName);
                    if ($arena === null) {
                        $sender->sendMessage("§cLa arena §e{$arenaName}§c no existe.");
                        return true;
                    }

                    $this->arenaEdit[$sender->getName()] = ["name" => $arenaName, "world" => $arena->getWorld()];
                    $this->startAdminMode($sender, $arenaName, true);
                    break; §e{$arenaName}§a.");
                    $sender->sendMessage("§aAhora usa §e/kb pos1 §ay §e/kb pos2 <y> §apara redefinir los spawns y la zona segura.");
                    break;

                case "setlimit":
                    if (!$sender->hasPermission("knockback.setlimit")) {
                        $sender->sendMessage("§cNo tienes permiso para configurar límites.");
                        return true;
                    }
                    if (!isset($args[1]) || !isset($args[2]) || !isset($args[3])) {
                        $sender->sendMessage("§cUso: /kb setlimit <arena> <min> <max>");
                        return true;
                    }
                    
                    $arenaName = $args[1];
                    $minPlayers = (int)$args[2];
                    $maxPlayers = (int)$args[3];
                    
                    if ($minPlayers < 2 || $maxPlayers > 20 || $minPlayers >= $maxPlayers) {
                        $sender->sendMessage("§cMínimo: 2, Máximo: 20, Min debe ser menor que Max");
                        return true;
                    }
                    
                    $arena = $this->arenaManager->getArena($arenaName);
                    if ($arena === null) {
                        $sender->sendMessage("§cLa arena §e{$arenaName}§c no existe.");
                        return true;
                    }
                    
                    $arena->setMinPlayers($minPlayers);
                    $arena->setMaxPlayers($maxPlayers);
                    $this->arenaManager->saveArenas();
                    
                    $sender->sendMessage("§aLímites de §e{$arenaName}§a configurados: §e{$minPlayers}-{$maxPlayers} jugadores");
                    break;

                case "createnpc":
                    if (!$sender->hasPermission("knockback.createnpc")) {
                        $sender->sendMessage("§cNo tienes permiso para crear NPCs.");
                        return true;
                    }
                    
                    $position = $sender->getPosition();
                    $npcId = uniqid();
                    
                    // Guardar NPC en archivo
                    $cfg = new Config($this->getDataFolder() . "npcs/npcs.yml", Config::YAML, []);
                    $npcs = $cfg->getAll();
                    $npcs[$npcId] = [
                        "world" => $position->getWorld()->getFolderName(),
                        "x" => $position->getX(),
                        "y" => $position->getY(),
                        "z" => $position->getZ()
                    ];
                    $cfg->setAll($npcs);
                    $cfg->save();
                    
                    // Spawn del NPC
                    $this->spawnNPC($position, $npcId);
                    $sender->sendMessage("§aNPC de KnockBack creado en tu posición!");
                    $sender->sendMessage("§7Los jugadores pueden hacer click para unirse a una arena aleatoria.");
                    break;

                case "arenas":
                    if (!$sender->hasPermission("knockback.arenas")) {
                        $sender->sendMessage("§cNo tienes permiso para ver el estado de arenas.");
                        return true;
                    }
                    
                    $arenas = $this->arenaManager->getArenas();
                    if (empty($arenas)) {
                        $sender->sendMessage("§cNo hay arenas creadas.");
                        return true;
                    }
                    
                    $sender->sendMessage("§e==== Estado de Arenas ====");
                    foreach ($arenas as $name => $arena) {
                        $playerCount = $this->gameManager->getPlayersInArena($name);
                        $minPlayers = $arena->getMinPlayers();
                        $maxPlayers = $arena->getMaxPlayers();
                        $status = $this->gameManager->getArenaStatus($name);
                        
                        $statusColor = match($status) {
                            "waiting" => "§e",
                            "starting" => "§a",
                            "playing" => "§b",
                            "ending" => "§c",
                            default => "§7"
                        };
                        
                        $sender->sendMessage("§a{$name}: §7{$playerCount}/{$maxPlayers} jugadores - {$statusColor}{$status}");
                    }
                    break;

                case "join":
                    if (!$sender->hasPermission("knockback.join")) {
                        $sender->sendMessage("§cNo tienes permiso para unirte a arenas.");
                        return true;
                    }
                    if (!isset($args[1])) {
                        $sender->sendMessage("§cUso: /kb join <nombre>");
                        return true;
                    }
                    
                    $arenaName = $args[1];
                    $this->gameManager->joinArena($sender, $arenaName, false);
                    break;

                case "leave":
                    if (!$sender->hasPermission("knockback.leave")) {
                        $sender->sendMessage("§cNo tienes permiso para salir de arenas.");
                        return true;
                    }
                    
                    if (!$this->arenaManager->isInArena($sender)) {
                        $sender->sendMessage("§cNo estás en ninguna arena.");
                        return true;
                    }
                    
                    $this->gameManager->removePlayerFromGame($sender);
                    break;

                case "coords":
                    if (!$sender->hasPermission("knockback.coords")) {
                        $sender->sendMessage("§cNo tienes permiso para ver coordenadas.");
                        return true;
                    }
                    $pos = $sender->getPosition();
                    $x = round($pos->getX(), 2);
                    $y = round($pos->getY(), 2);
                    $z = round($pos->getZ(), 2);
                    $world = $pos->getWorld()->getFolderName();
                    
                    $sender->sendMessage("§e==== Tu Posición Actual ====");
                    $sender->sendMessage("§aX: §f{$x}");
                    $sender->sendMessage("§aY: §f{$y} §7(Esta es la altura para zona segura)");
                    $sender->sendMessage("§aZ: §f{$z}");
                    $sender->sendMessage("§aMundo: §f{$world}");
                    $sender->sendMessage("§7Usa la altura Y en /kb pos2 <y>");
                    break;

                default:
                    $sender->sendMessage("§cSubcomando desconocido. Usa §e/kb §cpara ver la ayuda.");
                    break;
            }
            return true;
        }
        return false;
    }

    public function getSafeZonePlayers(): array {
        return $this->safeZonePlayers;
    }

    /**
     * Dar el kit de KnockBack Plus completo
     */
    private function giveKnockBackKit(Player $player): void {
        $player->getInventory()->clearAll();
        
        $player->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 999999, 1, false));
        $player->getEffects()->add(new EffectInstance(VanillaEffects::JUMP_BOOST(), 999999, 1, false));
        
        $stick = VanillaItems::STICK();
        $stick->addEnchantment(new EnchantmentInstance(VanillaEnchantments::KNOCKBACK(), 2));
        $stick->setCustomName("§bKnockBack Stick §7(Palo de Empuje)");
        $player->getInventory()->addItem($stick);

        $sandstone = VanillaBlocks::SANDSTONE()->asItem()->setCount(64);
        $sandstone->setCustomName("§eBloque Temporal §7(Se elimina automáticamente)");
        $player->getInventory()->addItem($sandstone);
        
        $pressurePlate = VanillaBlocks::HEAVY_WEIGHTED_PRESSURE_PLATE()->asItem()->setCount(3);
        $pressurePlate->setCustomName("§6Placa de Salto §7(Te impulsa hacia arriba)");
        $player->getInventory()->addItem($pressurePlate);
        
        $feather = VanillaItems::FEATHER();
        $feather->setCustomName("§fPluma de Vuelo §7(Clic derecho para volar)");
        $player->getInventory()->addItem($feather);
        
        $enderpearl = VanillaItems::ENDER_PEARL()->setCount(6);
        $enderpearl->setCustomName("§5Perla de Teletransporte");
        $player->getInventory()->addItem($enderpearl);
        
        $bow = VanillaItems::BOW();
        $bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::POWER(), 1));
        $bow->addEnchantment(new EnchantmentInstance(VanillaEnchantments::INFINITY(), 1));
        $bow->setCustomName("§cArco Encantado");
        $player->getInventory()->addItem($bow);
        
        $arrow = VanillaItems::ARROW()->setCount(3);
        $player->getInventory()->addItem($arrow);
        
        $web = VanillaBlocks::COBWEB()->asItem()->setCount(3);
        $web->setCustomName("§7Telaraña Táctica §7(Atrapa enemigos)");
        $player->getInventory()->addItem($web);
    }

    /**
     * Mostrar ayuda visual en modo administrador
     */
    private function showAdminModeHelp(Player $player): void {
        if (!isset($this->adminModeStep[$player->getName()])) {
            return;
        }
        
        $step = $this->adminModeStep[$player->getName()];
        $pos = $player->getPosition();
        $x = round($pos->getX(), 1);
        $y = round($pos->getY(), 1);
        $z = round($pos->getZ(), 1);
        
        switch ($step) {
            case 1:
                $player->sendActionBarMessage("§e[MODO ADMIN] §aElige posición para SPAWN 1 → §e/kb pos1 §7({$x}, {$y}, {$z})");
                break;
            case 2:
                $player->sendActionBarMessage("§e[MODO ADMIN] §aElige posición para SPAWN 2 → §e/kb pos2 <altura> §7({$x}, {$y}, {$z})");
                break;
            case 3:
                $player->sendActionBarMessage("§e[MODO ADMIN] §a¡Arena configurada! → §e/kb save §7para finalizar");
                break;
        }
    }

    /**
     * Iniciar modo administrador
     */
    private function startAdminMode(Player $player, string $arenaName, bool $isEdit = false): void {
        $this->adminMode[$player->getName()] = true;
        $this->adminModeStep[$player->getName()] = 1;
        
        // Título y mensajes de inicio
        $player->sendTitle("§b§l[MODO ADMIN]", $isEdit ? "§eEditando: {$arenaName}" : "§aCreando: {$arenaName}", 10, 40, 10);
        $player->sendMessage("§e========================================");
        $player->sendMessage("§b§l         MODO ADMINISTRADOR ACTIVADO");
        $player->sendMessage("§e========================================");
        $player->sendMessage("§aArena: §e{$arenaName}");
        $player->sendMessage("§aPaso 1/3: §fElige la posición del PRIMER SPAWN");
        $player->sendMessage("§7Muévete al lugar donde quieres el spawn 1 y usa:");
        $player->sendMessage("§e/kb pos1");
        $player->sendMessage("");
        $player->sendMessage("§cPara cancelar: §e/kb cancel");
        $player->sendMessage("§e========================================");
    }

    /**
     * Terminar modo administrador
     */
    private function stopAdminMode(Player $player): void {
        unset($this->adminMode[$player->getName()]);
        unset($this->adminModeStep[$player->getName()]);
        
        $player->sendTitle("§a§lMODO ADMIN", "§2FINALIZADO", 5, 30, 10);
        $player->sendMessage("§a§l[MODO ADMIN] §2¡Configuración completada exitosamente!");
    }

    /**
     * Cancelar modo administrador
     */
    private function cancelAdminMode(Player $player): void {
        $playerName = $player->getName();
        
        // Limpiar datos
        unset($this->adminMode[$playerName]);
        unset($this->adminModeStep[$playerName]);
        if (isset($this->arenaCreation[$playerName])) {
            unset($this->arenaCreation[$playerName]);
        }
        if (isset($this->arenaEdit[$playerName])) {
            unset($this->arenaEdit[$playerName]);
        }
        
        $player->sendTitle("§c§lMODO ADMIN", "§4CANCELADO", 5, 30, 10);
        $player->sendMessage("§c§l[MODO ADMIN] §4Configuración cancelada.");
        
        // Teleportar al spawn principal
        $mainSpawn = $this->getPlayerMainSpawn($player);
        $player->teleport($mainSpawn);
}
