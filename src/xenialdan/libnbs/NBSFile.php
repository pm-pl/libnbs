<?php
/*
 * References:
 * https://github.com/HielkeMinecraft/OpenNoteBlockStudio/
 * https://github.com/koca2000/NoteBlockAPI/
 * https://www.stuffbydavid.com/mcnbs/format
 * https://minecraft.gamepedia.com/Noteblock
 * https://www.minecraftforum.net/forums/mapping-and-modding-java-edition/resource-packs/resource-pack-discussion/1255570-making-music-with-playsound
 */

namespace xenialdan\libnbs;

use JsonSchema\Exception\ResourceNotFoundException;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\Filesystem;
use SplFileObject;
use function realpath;
use function var_dump;

class NBSFile{
	const INSTRUMENT_PIANO = 0;//0 = Piano (air)
	const INSTRUMENT_DOUBLE_BASS = 1;//1 = Double Bass (wood)
	const INSTRUMENT_BASS_DRUM = 2;//2 = Bass Drum (stone)
	const INSTRUMENT_SNARE = 3;//3 = Snare Drum (sand)
	const INSTRUMENT_CLICK = 4;//4 = Click (glass)
	const INSTRUMENT_GUITAR = 5;//5 = Guitar (wool)
	const INSTRUMENT_FLUTE = 6;//6 = Flute (Clay)
	const INSTRUMENT_BELL = 7;//7 = Bell (Block of Gold)
	const INSTRUMENT_CHIME = 8;//8 = Chime (Packed Ice)
	const INSTRUMENT_XYLOPHONE = 9;//9 = Xylophone (Bone Block)
	/**
	 * New instruments added by OpenNoteBlockStudio
	 * https://hielkeminecraft.github.io/OpenNoteBlockStudio/nbs
	 */
	const INSTRUMENT_IRONXYLOPHONE = 10;//10 = Iron Xylophone (Iron Block)
	const INSTRUMENT_COWBELL = 11;//11 = Cow Bell (Soul Sand)
	const INSTRUMENT_DIDGERIDOO = 12;//12 = Didgeridoo (Pumpkin)
	const INSTRUMENT_BIT = 13;//13 = Bit (Block of Emerald)
	const INSTRUMENT_BANJO = 14;//14 = Banjo (Hay)
	const INSTRUMENT_PLING = 15;//15 = Pling (Glowstone)

	/**
	 * Parses a Song from an InputStream and a Note Block Studio project file (.nbs)
	 *
	 * @param string $path path to the .nbs file
	 *
	 * @return Song object representing the given .nbs file
	 * @throws ResourceNotFoundException|BinaryDataException
	 * @see Song
	 */
	public static function parse(string $path) : Song{
		// int => Layer
		/** @phpstan-var array<int,Layer> $layerHashMap */
		$layerHashMap = [];

		### HEADER ###
		$path = Filesystem::cleanPath(realpath($path));
		$file = new SplFileObject($path);
		$file->rewind();
		//TODO test
		$fread = $file->fread($file->getSize());
		if($fread === false) throw new ResourceNotFoundException("Could not read file $path");
		$binaryStream = new NBSBinaryStream($fread);

		$file = null;
		unset($file);
		//
		$length = $binaryStream->getLShort();
		$firstCustomInstrument = 10;
		$nbsVersion = 0;
		if($length === 0){
			$nbsVersion = $binaryStream->getByte();
			$firstCustomInstrument = $binaryStream->getByte();
			if($nbsVersion >= 3)
				$length = $binaryStream->getLShort();
		}
		$songHeight = $binaryStream->getLShort();
		$title = $binaryStream->getString();
		$author = $binaryStream->getString();
		/*$originalAuthor = */
		$binaryStream->getString();
		$description = $binaryStream->getString();
		$speed = $binaryStream->getLShort() / 100;
		/*$autoSaving = */
		$binaryStream->getByte();
		/*$autoSavingDuration = */
		$binaryStream->getByte();
		/*$timeSignature = */
		$binaryStream->getByte();
		/*$minutesSpent = */
		$binaryStream->getInt();
		/*$leftClicks = */
		$binaryStream->getInt();
		/*$rightClicks = */
		$binaryStream->getInt();
		/*$blocksAdded = */
		$binaryStream->getInt();
		/*$blocksRemoved = */
		$binaryStream->getInt();
		/*$importedFileName = */
		$binaryStream->getString();
		if($nbsVersion >= 4){
			/*$loopOnOff = */
			$binaryStream->getByte();
			/*$maxLoopCount = */
			$binaryStream->getByte();
			/*$loopStartTick = */
			$binaryStream->getLShort();
		}

		### DATA ###
		$tick = -1;
		while(true){
			$jumpTicks = $binaryStream->getLShort();
			if($jumpTicks === 0) break;
			$tick += $jumpTicks;
			$layer = -1;
			while(true){
				$jumpLayers = $binaryStream->getLShort();
				if($jumpLayers === 0) break;
				$layer += $jumpLayers;
				$instrument = $binaryStream->getByte();
				$key = $binaryStream->getByte();
				//TODO custom instrument
				self::setNote($layer, $tick, $instrument, $key, $layerHashMap);
				if($nbsVersion >= 4){
					/*$velocity = */
					$binaryStream->getByte();
					/*$panning = */
					$binaryStream->getByte();
					/*$pitch = */
					$binaryStream->getSignedLShort();
				}
			}
		}
		if($nbsVersion > 0 && $nbsVersion < 3){
			$length = $tick;
		}

		### LAYER INFO ###
		for($i = 0; $i < $songHeight; $i++){
			$layer = $layerHashMap[$i] ?? null;

			$name = $binaryStream->getString();
			if($nbsVersion >= 4){
				/*$layerLock = */
				$binaryStream->getByte();
			}
			$volume = $binaryStream->getByte();
			$stereo = 100;

			if($nbsVersion >= 2){
				$stereo = $binaryStream->getByte();
			}
			if($layer !== null){
				$layer->setName($name);
				$layer->setVolume($volume);
				$layer->setStereo($stereo);
			}
		}

		$countCustom = $binaryStream->getByte();
		$customInstrumentsArray = [];
		for($index = 0; $index < $countCustom; $index++){
			$name = $binaryStream->getString();
			$soundFile = $binaryStream->getString();
			$pitch = $binaryStream->getByte();
			$pressKey = $binaryStream->getByte() === 1;
			$customInstrumentsArray[$index] = new CustomInstrument($index, $name, $soundFile, $pitch, $pressKey);
		}
		/*TODO customdiff
		if (firstcustominstrumentdiff < 0){
			ArrayList<CustomInstrument> customInstruments = CompatibilityUtils.getVersionCustomInstrumentsForSong(firstcustominstrument);
			customInstruments.addAll(Arrays.asList(customInstrumentsArray));
			customInstrumentsArray = customInstruments.toArray(customInstrumentsArray);
		} else {
			firstcustominstrument += firstcustominstrumentdiff;
		}
		*/
		return new Song($speed, $layerHashMap, $songHeight, $length, $title, $author, $description, $path, $firstCustomInstrument, $customInstrumentsArray);
	}

	/**
	 * Sets a note at a tick in a song
	 *
	 * @param int   $layerIndex
	 * @param int   $ticks
	 * @param int   $instrument
	 * @param int   $key
	 * @param array $layerHashMap
	 */
	private static function setNote(int $layerIndex, int $ticks, int $instrument, int $key, array &$layerHashMap) : void{
		($layerHashMap[$layerIndex] ??= new Layer("", 100))->setNote($ticks, new Note($instrument, $key));
	}

	public static function mapping(int $instrument) : string{
		//TODO custom sound support, figure out path in resource pack
		return match ($instrument) {
			NBSFile::INSTRUMENT_DOUBLE_BASS => "note.bass",//same as bassattack
			NBSFile::INSTRUMENT_BASS_DRUM => "note.bd",
			NBSFile::INSTRUMENT_SNARE => "note.snare",
			NBSFile::INSTRUMENT_CLICK => "note.hat",
			NBSFile::INSTRUMENT_GUITAR => "note.guitar",
			NBSFile::INSTRUMENT_FLUTE => "note.flute",
			NBSFile::INSTRUMENT_BELL => "note.bell",
			NBSFile::INSTRUMENT_CHIME => "note.chime",
			NBSFile::INSTRUMENT_XYLOPHONE => "note.xylophone",
			NBSFile::INSTRUMENT_IRONXYLOPHONE => "note.iron_xylophone",
			NBSFile::INSTRUMENT_COWBELL => "note.cow_bell",
			NBSFile::INSTRUMENT_DIDGERIDOO => "note.didgeridoo",
			NBSFile::INSTRUMENT_BIT => "note.bit",
			NBSFile::INSTRUMENT_BANJO => "note.banjo",
			NBSFile::INSTRUMENT_PLING => "note.pling",
			default => "note.harp"
		};
	}
}