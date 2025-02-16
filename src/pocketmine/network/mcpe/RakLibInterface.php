<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

namespace pocketmine\network\mcpe;

use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\Network;
use pocketmine\network\AdvancedSourceInterface;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\BatchPacket;
use pocketmine\Player;
use pocketmine\Server;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface {

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var string[] */
	private $identifiers;

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	/**
	 * RakLibInterface constructor.
	 *
	 * @param Server $server
	 */
	public function __construct(Server $server){

		$this->server = $server;
		$this->identifiers = [];

		$this->rakLib = new RakLibServer($this->server->getLogger(), $this->server->getLoader(), $this->server->getPort(), $this->server->getIp() === "" ? "0.0.0.0" : $this->server->getIp());
		$this->interface = new ServerHandler($this->rakLib, $this);
	}

	/**
	 * @param Network $network
	 */
	public function setNetwork(Network $network){
		$this->network = $network;
	}

	/**
	 * @return bool
	 * @throws \Exception
	 */
	public function process(){
		$work = false;
		if($this->interface->handlePacket()){
			$work = true;
			$lasttime = time();
			while($this->interface->handlePacket()){
				$diff = time() - $lasttime;
				if($diff >= 1) break;
			}
		}

		if(!$this->rakLib->isRunning() and !$this->rakLib->isShutdown()){
			$this->network->unregisterInterface($this);

			throw new \Exception("RakLib Thread crashed");
		}

		return $work;
	}

	/**
	 * @param string $identifier
	 * @param string $reason
	 */
	public function closeSession($identifier, $reason){
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($player->getLeaveMessage(), $reason);
		}
	}

	/**
	 * @param Player $player
	 * @param string $reason
	 */
	public function close(Player $player, $reason = "unknown reason"){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown(){
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->interface->emergencyShutdown();
	}

	/**
	 * @param string     $identifier
	 * @param string     $address
	 * @param int        $port
	 * @param int|string $clientID
	 */
	public function openSession($identifier, $address, $port, $clientID){
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	/**
	 * @param string             $identifier
	 * @param EncapsulatedPacket $packet
	 * @param int                $flags
	 */
	public function handleEncapsulated($identifier, EncapsulatedPacket $packet, $flags){
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer);
					if($pk !== null){
						$pk->decode();
						assert($pk->feof(), "Still " . strlen(substr($pk->buffer, $pk->offset)) . " bytes unread!");
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				$logger = $this->server->getLogger();
				if(\pocketmine\DEBUG > 1 and isset($pk)){
					$logger->debug("Exception in packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
				}
				$logger->logException($e);
			}
		}
	}

	/**
	 * @param string $address
	 * @param int    $timeout
	 */
	public function blockAddress($address, $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	/**
	 * @param $address
	 */
	public function unblockAddress($address){
		$this->interface->unblockAddress($address);
	}

	/**
	 * @param string $address
	 * @param int    $port
	 * @param string $payload
	 */
	public function handleRaw($address, $port, $payload){
		$this->server->handlePacket($address, $port, $payload);
	}

	/**
	 * @param string $address
	 * @param int    $port
	 * @param string $payload
	 */
	public function sendRawPacket($address, $port, $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	/**
	 * @param string $identifier
	 * @param int    $identifierACK
	 */
	public function notifyACK($identifier, $identifierACK){

	}

	/**
	 * @param string $name
	 */
	public function setName($name){

		if($this->server->isDServerEnabled()){
			if($this->server->dserverConfig["motdMaxPlayers"] > 0) $pc = $this->server->dserverConfig["motdMaxPlayers"];
			elseif($this->server->dserverConfig["motdAllPlayers"]) $pc = $this->server->getDServerMaxPlayers();
			else $pc = $this->server->getMaxPlayers();

			if($this->server->dserverConfig["motdPlayers"]) $poc = $this->server->getDServerOnlinePlayers();
			else $poc = count($this->server->getOnlinePlayers());
		}else{
			$info = $this->server->getQueryInformation();
			$pc = $info->getMaxPlayerCount();
			$poc = $info->getPlayerCount();
		}

		$this->interface->sendOption("name",
			"MCPE;" . rtrim(addcslashes($name, ";"), '\\') . ";" .
			ProtocolInfo::CURRENT_PROTOCOL . ";" .
			ProtocolInfo::MINECRAFT_VERSION_NETWORK . ";" .
			$poc . ";" .
			$pc
		);
	}

	/**
	 * @param $name
	 */
	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", (bool) $name);
	}

	/**
	 * @param string $name
	 * @param string $value
	 */
	public function handleOption($name, $value){
		if($name === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	/**
	 * @param Player     $player
	 * @param DataPacket $packet
	 * @param bool       $needACK
	 * @param bool       $immediate
	 *
	 * @return int|null
	 */
	public function putPacket(Player $player, DataPacket $packet, $needACK = false, $immediate = false){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			if(!$packet->isEncoded){
				$packet->encode();
				$packet->isEncoded = true;
			}

			if($packet instanceof BatchPacket){
				if($needACK){
					$pk = new EncapsulatedPacket();
					$pk->buffer = $packet->buffer;
					$pk->reliability = PacketReliability::RELIABLE_ORDERED;
					$pk->orderChannel = 0;

					if($needACK === true){
						$pk->identifierACK = $this->identifiersACK[$identifier]++;
					}
				}else{
					if(!isset($packet->__encapsulatedPacket)){
						$packet->__encapsulatedPacket = new CachedEncapsulatedPacket;
						$packet->__encapsulatedPacket->identifierACK = null;
						$packet->__encapsulatedPacket->buffer = $packet->buffer; // #blameshoghi
						$packet->__encapsulatedPacket->reliability = PacketReliability::RELIABLE_ORDERED;
						$packet->__encapsulatedPacket->orderChannel = 0;
					}
					$pk = $packet->__encapsulatedPacket;
				}

				$this->interface->sendEncapsulated($identifier, $pk, ($needACK === true ? RakLib::FLAG_NEED_ACK : 0) | ($immediate === true ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));
				return $pk->identifierACK;
			}else{
				$this->server->batchPackets([$player], [$packet], true);
				return null;
			}
		}

		return null;
	}

	/**
	 * @param $buffer
	 *
	 * @return null|DataPacket
	 */
	private function getPacket($buffer){
		$pid = ord($buffer[0]);
		if(($data = $this->network->getPacket($pid)) === null){
			return null;
		}
		$data->setBuffer($buffer, 1);

		return $data;
	}
}
