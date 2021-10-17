<?php

/**
 * Copyright 2018-2020 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\BedWars\arena;

use pocketmine\level\Level;

/**
 * Class MapReset
 * @package BedWars\arena
 */
class MapReset {

    /** @var Arena $plugin */
    public $plugin;

    /**
     * MapReset constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
    }

    /**
     * @param Level $level
     */
    public function saveMap(Level $level) {
        $level->save(true);

        $levelPath = $this->plugin->plugin->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $level->getFolderName();
        $zipPath = $this->plugin->plugin->getDataFolder() . "saves" . DIRECTORY_SEPARATOR . $level->getFolderName() . ".zip";

        $zip = new \ZipArchive();

        if(is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(realpath($levelPath)), \RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if($file->isFile()) {
                $filePath = $file->getPath() . DIRECTORY_SEPARATOR . $file->getBasename();
                $localPath = substr($filePath, strlen($this->plugin->plugin->getServer()->getDataPath() . "worlds"));
                $zip->addFile($filePath, $localPath);
            }
        }

        $zip->close();
    }

    /**
     * @param string $folderName
     * @param bool $justSave
     *
     * @return Level|null
     */
    public function loadMap(string $folderName, bool $justSave = false): ?Level {
        if(!$this->plugin->plugin->getServer()->isLevelGenerated($folderName)) {
            return null;
        }

        if($this->plugin->plugin->getServer()->isLevelLoaded($folderName)) {
            $this->plugin->plugin->getServer()->getLevelByName($folderName)->unload(true);
        }

        $zipPath = $this->plugin->plugin->getDataFolder() . "saves" . DIRECTORY_SEPARATOR . $folderName . ".zip";

        if(!file_exists($zipPath)) {
            $this->plugin->plugin->getServer()->getLogger()->error("Could not reload map ($folderName). File wasn't found, try save level in setup mode.");
            return null;
        }

        $zipArchive = new \ZipArchive();
        $zipArchive->open($zipPath);
        $zipArchive->extractTo($this->plugin->plugin->getServer()->getDataPath() . "worlds");
        $zipArchive->close();

        if($justSave) {
            return null;
        }

        $this->plugin->plugin->getServer()->loadLevel($folderName);
        return $this->plugin->plugin->getServer()->getLevelByName($folderName);
    }
}