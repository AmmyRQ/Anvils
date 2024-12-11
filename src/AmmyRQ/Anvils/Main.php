<?php

declare(strict_types=1);

namespace AmmyRQ\Anvils;

use pocketmine\block\{Anvil, inventory\AnvilInventory,};
use pocketmine\event\{Listener,
    inventory\InventoryCloseEvent,
    player\PlayerDeathEvent,
    player\PlayerInteractEvent,
    player\PlayerQuitEvent,
    server\DataPacketReceiveEvent};
use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\{
    ItemStackRequestPacket,
    types\inventory\ContainerUIIds,
    types\inventory\stackrequest\PlaceStackRequestAction
};

class Main extends PluginBase implements Listener
{

    /**
     * @return void
     */
    public function onEnable() : void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * @param PlayerInteractEvent $event
     * @return void
     */
    public function onInteract(PlayerInteractEvent $event) : void
    {
        $block = $event->getBlock();
        $player = $event->getPlayer();

        if ($block instanceof Anvil && $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK)
            AnvilManager::registerData($player, $block);
    }

    /**
     * @param InventoryCloseEvent $event
     * @return void
     */
    public function onInventoryClose(InventoryCloseEvent $event) : void
    {
        $player = $event->getPlayer();
        $inv = $event->getInventory();

        if($inv instanceof AnvilInventory)
            AnvilManager::removePlayerData($player);
    }

    /**
     * @param PlayerQuitEvent|PlayerDeathEvent $event
     * @return void
     */
    public function onDifferentPlayerEvents(PlayerQuitEvent|PlayerDeathEvent $event) : void
    {
        $player = $event->getPlayer();
        AnvilManager::removePlayerData($player);
    }

    /**
     * @param DataPacketReceiveEvent $event
     * @return void
     */
    public function onReceive(DataPacketReceiveEvent $event) : void
    {
        $player = $event->getOrigin()->getPlayer();

        if(!is_null($player))
        {
            $inv = $player->getCurrentWindow();

            //Anvil window
            if($inv instanceof AnvilInventory)
            {
                $pk = $event->getPacket();
                if($pk instanceof ItemStackRequestPacket)
                {
                    foreach($pk->getRequests() as $request)
                    {
                        foreach ($request->getActions() as $action)
                        {
                            if ($action instanceof PlaceStackRequestAction)
                            {
                                if ($action->getSource()->getContainerName()->getContainerId() === ContainerUIIds::CREATED_OUTPUT) //Picking up the object (Result)
                                {
                                    try
                                    {
                                        if (!AnvilManager::processResult($player, $inv, $request->getFilterStrings()))
                                            $event->cancel();
                                    }
                                    catch (exception\UndefinedPlayerException $e)
                                    {
                                        $this->getServer()->getLogger()->error($e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
