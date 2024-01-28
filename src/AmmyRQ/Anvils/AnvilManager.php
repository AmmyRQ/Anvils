<?php

declare(strict_types=1);

namespace AmmyRQ\Anvils;


use AmmyRQ\Anvils\utils\EnchantmentsXP_Cost;
use pocketmine\block\{Anvil, VanillaBlocks, inventory\AnvilInventory};
use pocketmine\item\{Durable, enchantment\EnchantmentInstance, VanillaItems};
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\player\{Player, GameMode};
use pocketmine\world\{particle\BlockBreakParticle, sound\AnvilBreakSound, sound\AnvilUseSound};

class AnvilManager
{

    /**
     * @var list<string, Anvil>
     */
    private static array $anvils = [];

    /**
     * @return array
     */
    public static function getAllData() : array
    {
        return self::$anvils;
    }

    /**
     * @param Player $player
     * @return void
     */
    public static function removePlayerData(Player $player) : void
    {
        if(array_key_exists($player->getName(), self::$anvils))
            unset(self::$anvils[$player->getName()]);
    }

    /**
     * @param Player $player
     * @param Anvil $block
     * @return void
     */
    public static function registerData(Player $player, Anvil $block) : void
    {
        if(array_key_exists($player->getName(), self::$anvils))
            unset(self::$anvils[$player->getName()]);

        self::$anvils[$player->getName()] = $block;
    }

    /**
     * @param Player $player
     * @param AnvilInventory $inv
     * @param array $filterStrings
     * @return bool
     */
    public static function processResult(Player $player, AnvilInventory $inv, array $filterStrings) : bool
    {
        $xpCost = 0;
        $sourceItem = $inv->getItem(AnvilInventory::SLOT_INPUT);
        $slaughterItem = $inv->getItem(AnvilInventory::SLOT_MATERIAL);
        $resultItem = clone $sourceItem;
        $amountToBeSlaughtered = 1; //Indicates the number of objects to be slaughtered. Mainly used in unit repair

        /*
         * Checks the filters strings. If it is NOT empty, the first element will be taken as the new item name.
         * NOTE: Sometimes, package does not recognize when very few letters of the name are changed
         */
        empty($filterStrings) ? $customName = null : $customName = $filterStrings[0];

        //If there is no "slaughter" (2nd anvil slot)
        if ($slaughterItem->getName() === VanillaItems::AIR()->getName())
        {
            //Checks if the item name will be modified
            if (!is_null($customName))
            {
                //Verifies that the name of the resulting item is not the same as the source item
                if($sourceItem->getCustomName() !== $customName)
                {
                    $xpCost += 1;
                    $resultItem->setCustomName($customName);
                }
            }
        }
        //In case there is an item to be slaughtered
        else
        {
            if (!is_null($customName))
            {
                if($sourceItem->getCustomName() !== $customName)
                {
                    $xpCost += 1;
                    $resultItem->setCustomName($customName);
                }
            }

            /*
             * Repairs the item if possible (2 XP cost)
             * slaughtering an item of the same type
             */
            if ($sourceItem instanceof Durable && $slaughterItem instanceof Durable && $resultItem instanceof Durable)
            {
                //If the item to be slaughtered is not damaged, the result item will be fully repaired.
                if ($sourceItem->getDamage() > 0 && $slaughterItem->getDamage() === 0)
                    $resultItem->setDamage(0);
                //If the source item or the slaughterer item are damaged
                else
                {
                    //The object is repaired with the duration of the object to be slaughtered + 12% of the maximum durability.
                    $durability =
                        ($sourceItem->getMaxDurability() - $sourceItem->getDamage())
                        + ($sourceItem->getMaxDurability() - $slaughterItem->getDamage())
                        + (int)(0.12*$sourceItem->getMaxDurability());

                    if($durability > $sourceItem->getMaxDurability())
                        $durability = $sourceItem->getMaxDurability();

                    $resultItem->setDamage($sourceItem->getMaxDurability() - $durability);
                }

                $xpCost += 2;
            }
            /*
             * Unit repair.
             * Oak planks can be used to repair a wooden sword (for example). Each oak plank represents one unit
             */
            else if($sourceItem instanceof Durable && !$slaughterItem instanceof Durable && $resultItem instanceof Durable)
            {
                $durability = 0;

                //Performs a for loop to obtain the number of units to slaughter.
                for($i = 1; $i <= $slaughterItem->getCount(); $i++)
                {
                    //Each unit restores up to 25% of the total durability of the item
                    $durability =
                        ($sourceItem->getMaxDurability() - $sourceItem->getDamage())
                        + (int)floor($sourceItem->getMaxDurability()*0.25)
                        * $i;
                    $amountToBeSlaughtered = $i;

                    //Ensures that the durability does not exceed that allowed by the item
                    if($durability > $sourceItem->getMaxDurability())
                    {
                        $durability = $sourceItem->getMaxDurability();
                        break;
                    }
                }

                $resultItem->setDamage($sourceItem->getMaxDurability() - $durability);

                //Each unit used represents 1 more XP level
                $xpCost += $amountToBeSlaughtered;
            }

            //Replicates the enchantments of the object to be slaughtered
            if ($slaughterItem->hasEnchantments())
            {
                foreach ($slaughterItem->getEnchantments() as $enchant)
                {
                    //Obtains the name of the enchantment using the PMMP language API (english).
                    $language = new Language(Language::FALLBACK_LANGUAGE);
                    $name = ($enchantmentName = $enchant->getType()->getName()) instanceof Translatable ?
                        $language->translate($enchantmentName) :
                        $language->translateString($enchantmentName);

                    //Enchantment levels calculation.
                    if($sourceItem->hasEnchantment($enchant->getType()))
                    {
                        $modifiedEnchant = new EnchantmentInstance($enchant->getType(), $enchant->getLevel());

                        //Verifies that an extra level can be added when combining enchantments of the same level
                        if
                        (
                            $sourceItem->getEnchantment($enchant->getType())->getLevel() === $enchant->getLevel()
                            &&
                            $sourceItem->getEnchantment($enchant->getType())->getLevel()+1 <= $enchant->getType()->getMaxLevel()
                        )
                            $modifiedEnchant = new EnchantmentInstance($enchant->getType(), $enchant->getLevel() + 1);

                        /**
                         * XP cost calculation (Enchantment level * Enchantment cost)
                         * @see EnchantmentsXP_Cost
                         */
                        $xpCost += ($modifiedEnchant->getLevel() - $enchant->getLevel()) * EnchantmentsXP_Cost::$costPerLevel[strtolower($name)];
                        $resultItem->addEnchantment($modifiedEnchant);
                    }
                    else
                    {
                        $xpCost += $enchant->getLevel() * EnchantmentsXP_Cost::$costPerLevel[strtolower($name)];
                        $resultItem->addEnchantment($enchant);
                    }

                }
            }
            //Deletes the slaughtered items
            $inv->setItem(1, $inv->getItem(1)->setCount($inv->getItem(1)->getCount() - $amountToBeSlaughtered));
        }

        //Deletes the source item
        $inv->setItem(0, $inv->getItem(0)->setCount(0));

        if ($player->getInventory()->canAddItem($resultItem))
        {
            //Reduces the player's xp if not in creative mode
            if($player->getGamemode() !== GameMode::CREATIVE)
                $player->getXpManager()->subtractXpLevels($xpCost);

            $player->getInventory()->addItem($resultItem);

            //Damages the anvil if it is not broken yet
            if ($player->getWorld()->getBlock(self::$anvils[$player->getName()]->getPosition())->getTypeId() !== VanillaBlocks::AIR()->getTypeId())
                self::damageAnvil(self::$anvils[$player->getName()]);

            return true;
        }
        else
            return false;
    }

    /**
    * @param Anvil $block
    * @return void
    */
    private static function damageAnvil(Anvil $block) : void
    {
        $world = $block->getPosition()->getWorld();

        //If the anvil is severely damaged, the block "breaks"
        if($block->getDamage()+1 >= Anvil::VERY_DAMAGED)
        {
            $world->addSound($block->getPosition(), new AnvilBreakSound());
            $world->setBlock($block->getPosition(), VanillaBlocks::AIR());
            $world->addParticle($block->getPosition(), new BlockBreakParticle(VanillaBlocks::ANVIL()));
        }
        else
        {
            $world->addSound($block->getPosition(), new AnvilUseSound());
            $block->setDamage($block->getDamage()+1);
        }
    }
}
