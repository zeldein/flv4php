<?php
/*
 Copyright 2006 Iván Montes, Morten Hundevad

 This file is part of FLV tools for PHP (FLV4PHP from now on).

 FLV4PHP is free software; you can redistribute it and/or modify it under the 
 terms of the GNU General var License as published by the Free Software 
 Foundation; either version 2 of the License, or (at your option) any later 
 version.

 FLV4PHP is distributed in the hope that it will be useful, but WITHOUT ANY 
 WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR 
 A PARTICULAR PURPOSE. See the GNU General var License for more details.

 You should have received a copy of the GNU General var License along with 
 FLV4PHP; if not, write to the Free Software Foundation, Inc., 51 Franklin 
 Street, Fifth Floor, Boston, MA 02110-1301, USA
*/

define('FLV_INCLUDE_PATH', dirname(__FILE__) . '/');
 
define('FLV_SECRET_KEY', 'flv_key');

define('FLV_VERSION', 'V0.16');

require_once(FLV_INCLUDE_PATH . 'Tag.php');
require_once(FLV_INCLUDE_PATH . 'Util/AMFSerialize.php');
require_once(FLV_INCLUDE_PATH . 'Util/AMFUnserialize.php');

define('FLV_HEADER_SIGNATURE', 'FLV');
define('FLV_HEADER_SIZE', 9);

/**
* Parse a .flv file to extract all the 'tag' information
*/
class FLV {
	/** The FLV header signature */
	var $FLV_HEADER_SIGNATURE = FLV_HEADER_SIGNATURE;
	
    /** The FLV main header size */
	var $FLV_HEADER_SIZE = FLV_HEADER_SIZE;
	
    /** The FLV tag header size */
    var $TAG_HEADER_SIZE = FLV_TAG_HEADER_SIZE;
    
    /** 
		Maximun number of bytes to process as tag body. This is a safety meassure against
		corrupted FLV files.
	*/
    var $MAX_TAG_BODY_SIZE = FLV_TAG_MAX_BODY_SIZE;
	
  	var $filename;
  	var $metadata;
  	var $metadataend;
	
  	var $db_link_id;
  	var $lock_key;
  	var $lock_id;

  	var $fh;
    var $lastTagSize = 0;
    
    function FLV( $fname = false, $timeout = 30 )
    {
		if($fname) return $this->open($fname,$timeout);
		return false;
    }
	
	/*
	*
	* @param string $fname	Locatsion off file
	* @param int $timeout	Script Time out default 30, when using external ling for downloading..
	* @return true/false	if sucess/failed.
	*/
    function open( $fname = false, $timeout = 30 )
    {
		if($fname) {
			$this->filename = $fname;
			
			$this->setKey(basename($fname, ".flv"));
			
			$url = parse_url($fname);
			
			if($url['scheme']) {
				set_time_limit($timeout);
				$tempcontent = file_get_contents($fname);
				if ($tempcontent) {
					$this->fh = tmpfile();
					if (!$this->fh) die('Unable to Make temporeary file');
					fwrite($this->fh, $tempcontent);
					rewind($this->fh);
				}
			} else {
				$this->fh = @fopen( $fname, 'r' );
				if (!$this->fh) die('Unable to open the file');
			}
		   
			$hdr = fread( $this->fh, $this->FLV_HEADER_SIZE );
			//check file header signature
			if ( substr($hdr, 0, 3) !== $this->FLV_HEADER_SIGNATURE ) die('The header signature does not match');
	
			$this->version = ord($hdr[3]);
			$this->hasVideo = (bool)(ord($hdr[4]) & 0x01);
			$this->hasAudio = (bool)(ord($hdr[4]) & 0x04);
			
			$this->bodyOfs =	(ord($hdr[5]) << 24) +
								(ord($hdr[6]) << 16) +
								(ord($hdr[7]) << 8) +
								(ord($hdr[8]));
	
			$this->eof = false;
		
			$this->getMedaData();
			return true;
		}
		return false;
    }
	
	/**
	* Move to behining off the File ( after first header )
	*/
    function start()
    {
		fseek( $this->fh, $this->bodyOfs );
		$this->eof = false;
    }
    
    /**
	* Close a previously open FLV file
	*/
    function close()
    {
    	fclose( $this->fh );    
    }
    
    
	/**
	* Returns the MetaData Tag
	*/
    function getMedaData()
    {
		$this->start();

        $hdr = fread( $this->fh, $this->TAG_HEADER_SIZE );

		if (strlen($hdr) < $this->TAG_HEADER_SIZE) {
		    $this->eof = true;
		   	return NULL;
		}

		// Get the tag object by skiping the first 4 bytes which tell the previous tag size
		$tag = FLV_Tag::getTag( substr( $hdr, 4 ) );

		// Read at most MAX_TAG_BODY_SIZE bytes of the body
		$bytesToRead = min( $this->MAX_TAG_BODY_SIZE, $tag->size );
		$tag->setBody( fread( $this->fh, $bytesToRead ) );

		// Check if the tag body has to be processed

		$tag->analyze();

		// If the tag was skipped or the body size was larger than MAX_TAG_BODY_SIZE
		if ($tag->size > $bytesToRead) fseek( $this->fh, $tag->size-$bytesToRead, SEEK_CUR );
		
		$this->lastTagSize = $tag->size + $this->TAG_HEADER_SIZE - 4;
		
		$this->metadata = $tag->data;

		$this->metadataend = ftell($this->fh);
		return $tag;		
    }

	/**
	* Returns the MetaData Tag
	*
	* @param array $newMetaData		Array off new metadata
	* @param string $merge			Merge original array with new one
	* @return string				New Metadata + next tag's previous size
	*/
    function createMedaData($newMetaData = false,$merge = true)
    {
		fseek( $this->fh, $this->bodyOfs );

		//if the metadata is pressent in the file merge it with the generated one
		$amf = new FLV_Util_AMFSerialize();

		if (!is_array($newMetaData)) {
			if($merge && is_array($this->metadata)) {
				$newMetaData = array_merge( $this->metadata, $newMetaData );
				$serMeta = $amf->serialize('onMetaData') . $amf->serialize($newMetaData);
			} else {
				$serMeta = $amf->serialize('onMetaData') . $amf->serialize($newMetaData);			
			}
		} else {
			$serMeta = $amf->serialize('onMetaData') . $amf->serialize($this->metadata);		
		}

		$out = pack('N', 0);									// PreviousTagSize
		$out.= pack('C', FLV_TAG_TYPE_DATA);					// Type
		$out.= pack('Cn', "\x00", strlen($serMeta));			// BodyLength assumes it's shorter than 64Kb
		$out.= pack('N', 0);									// Time stamp (not used)
		$out.= pack('Cn', 0, 0);								// Stream ID (not used) <---- WHERE IS THIS comming from
		$out.= $serMeta;										// Metadata Body
		$out.= pack('N', strlen($serMeta) + 1 + 3 + 4 + 3); 	// PreviousTagSize
		return $out;
    }
	
	/**
	* Play the flv and close file after
	*
	* @param int $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param int $seekat			Start playback at..
	* @param array $newMetaData		Array off new metadata
	* @param bool $merge			Merge original array with new one			
	*/
    function playFlv($limitSpeed = 0,$seekat = 0,$newMetaData = false,$merge = true)
    {
		session_write_close();		
		$this->setHeader(true);
		
		header("Content-Disposition: filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));

		if($seekat != 0) {
			print(pack('N', 9 ));
	      	fseek($this->fh, $seekat);
		} else {
			header("Content-Length: " .(string)(filesize($file)));
			if(!is_array($newMetaData)) {
				$newMetaData = array();
				$newMetaData['metadatacreator'] = 'FLV Editor for PHP '.FLV_VERSION;
				$newMetaData['creator'] = 'FLV Editor for PHP '.FLV_VERSION;				
				$newMetaData['metadatadate'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
			}

			print($this->createMedaData($newMetaData,$merge));
			fseek($this->fh, 0);											// Rewind the movie
			fseek($this->fh, $this->metadataend+4);							// Skip the Original metadata
		}
		
		if ($limitSpeed) {
			if ($this->metadata["videodatarate"] || $this->metadata["audiodatarate"]) $limitSpeed = ceil(($this->metadata["videodatarate"]+$this->metadata["audiodatarate"])/8)+$limitSpeed-1;
			else $limitSpeed = false;
		}
		
		set_time_limit(0);
		print(fread($this->fh, 50000));
		while(!feof($this->fh)) {
			if ($limitSpeed) {
				print(fread($this->fh, round($limitSpeed*(1024/32))));
				flush();
				usleep(31250);
			 } else {
				print(fread($this->fh, 1024));			 
			 }
		}
		$this->close();
    }

	/**
	* Play the flv  with lock and close file after
	*
	* @param int $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param int $seekat			Start playback at..
	* @param array $newMetaData		Array off new metadata
	* @param bool $merge			Merge original array with new one
	* @param bool $usedb			use database for key veryfying
	*/
    function playFlvLock($limitSpeed = 0,$seekat = 0,$newMetaData = false,$merge = true,$usedb = false)
    {
		if ( $this->validLock($usedb) ) {
			$this->playFlv($limitSpeed,$seekat,$newMetaData,$merge);
		} else {
			$this->close();
			die("Hackking attempt");
			die(header("HTTP/1.0 404 Not Found"));
		}
	}
	
	/**
	* Get Flv Thumb output's a thumb clip from offset point, locate a key frame and from there output's duration
	* if no key frame is found it use the first key frame.
	*
	* @param int $offset			Offset in ms
	* @param bool $usemetadata		Use metadata to attempt to find first keyframe
	*/
    function getFlvThumb($offset=2000,$usemetadata=false) {
		session_write_close();
		$this->setHeader();
	
		header("Content-Disposition: filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));
		print(pack('N', 9 ));
		

		$this->start();
		$skipTagTypes = array();
		$skipTagTypes[FLV_TAG_TYPE_AUDIO] = FLV_TAG_TYPE_AUDIO;

		if($usemetadata && $offset) {
			foreach( $this->metadata['keyframes']['times'] as $key => $value){
				if( $value >= ($offset/1000) ) {
					fseek($this->fh,$this->metadata['keyframes']['filepositions'][$key]-4);
					break;
				}
			}
		}
		
		while ($tag = $this->getTag($skipTagTypes)) {
			if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
				if ($tag->timestamp >= $offset && $tag->frametype == 1 ) {
					$fhpos = $this->getTagOffset();
					$fhposend = $tag->end;
					break;
				}
			}
			//Does it actually help with memory allocation?
			unset($tag);
		}

		if(!$fhposend) {
			$offset = $fhposend = $fhpos = 0;
			$this->start();
			while ($tag = $this->getTag($skipTagTypes)) {
				if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
					if ($tag->timestamp >= $offset && $tag->frametype == 1 ) {
						$fhpos = $this->getTagOffset();
						$fhposend = $tag->end;
						break;
					}
				}
				//Does it actually help with memory allocation?
				unset($tag);
			}
		}
		
		rewind($this->fh);
		fseek($this->fh, $fhpos);
		print(fread($this->fh, ($fhposend-$fhpos)));
		$this->close();		
	}

	/**
	* Get Flv Thumb output's a thumb clip from offset point, locate a key frame and from there output's duration
	* if no key frame is found it use the first key frame.
	*
	* @param int $offset			Offset in ms
	* @param int $duration			Duratsion in ms
	* @param bool $usemetadata		Use metadata to attempt to find first keyframe
	*/
    function getFlvPreview($offset=2000,$duration=2000,$usemetadata=false) {
		session_write_close();

		$this->setHeader();
	
		header("Content-Disposition: filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));
		print(pack('N', 9 ));

		$this->start();
		
		$skipTagTypes = array();
		$skipTagTypes[FLV_TAG_TYPE_AUDIO] = FLV_TAG_TYPE_AUDIO;

		if($usemetadata && $offset) {
			foreach( $this->metadata['keyframes']['times'] as $key => $value){
				if( $value >= ($offset/1000) ) {
					fseek($this->fh,$this->metadata['keyframes']['filepositions'][$key]-4);
					break;
				}
			}
		}
		
		while ($tag = $this->getTag($skipTagTypes)) {
			if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
				if (!$fhpos && $tag->timestamp >= $offset && $tag->frametype == 1 ) {
					$fhpos = $this->getTagOffset();
					$timestamp = $tag->timestamp;
				} else if ($fhpos) {
					if ($tag->timestamp >= ($duration+$timestamp)) {
						$fhposend = $tag->end;
						break;
					}
				}
//				if(!$fhpos && $tag->timestamp >= ($offset+$duration)) break;				
			}
			//Does it actually help with memory allocation?
			unset($tag);
		}

		if(!$fhposend) {
			$offset = $fhposend = $fhpos = 0;
			$this->start();
			while ($tag = $this->getTag($skipTagTypes)) {
				if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
					if (!$fhpos && $tag->timestamp >= $offset && $tag->frametype == 1 ) {
						$fhpos = $this->getTagOffset();
						$timestamp = $tag->timestamp;						
					} else if ($fhpos) {
						$fhposend = $tag->end;
						if ($tag->timestamp >= ($duration+$timestamp) ) {
							break;
						}
					}
				}
				//Does it actually help with memory allocation?
				unset($tag);
			}
		}
				
		rewind($this->fh);
		fseek($this->fh, $fhpos);
		print(fread($this->fh, ($fhposend-$fhpos)));
		$this->close();
	}

	/**
	* Download Flv
	*
	* @param int $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param array $newMetaData		Array off new metadata
	* @param bool $merge			Merge original array with new one
	*/
    function downloadFlv($limitSpeed = 0,$newMetaData = false,$merge = true)
    {
		session_write_close();	
		$this->setHeader(true);

		header("Content-Disposition: attachment; filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));

		if(!is_array($newMetaData)) {
			$newMetaData = array();
			$newMetaData['metadatacreator'] = 'FLV Editor for PHP '.FLV_VERSION;
			$newMetaData['metadatadate'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
		}

		print($this->createMedaData($newMetaData,$merge));
		fseek($this->fh, 0);											// Rewind the movie
		fseek($this->fh, $this->metadataend+4);							// Skip the Original metadata
		
		if ($limitSpeed) {
			if ($this->metadata["videodatarate"] || $this->metadata["audiodatarate"]) $limitSpeed = ceil(($this->metadata["videodatarate"]+$this->metadata["audiodatarate"])/8)+$limitSpeed-1;
			else $limitSpeed = false;
		}
		
		set_time_limit(0);
		while(!feof($this->fh)) {
			if ($limitSpeed) {
				print(fread($this->fh, round($limitSpeed*(1024/32))));
				flush();
				usleep(31250);
			 } else {
				print(fread($this->fh, 1024));			 
			 }
		}
		$this->close();	
	}
	
	/*
	* Flv php header
	*
	* @param bool $nocashe			Use No Cashe ?
	*/
    function setHeader($nocashe=false)
    {
		header("Content-Type: video/x-flv");
//		header("Content-Length: " .(string)(filesize($this->filename)) );
		if($nocashe){
			// Date in the past
			header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
			// always modified
			header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
			// HTTP/1.1
			header("Cache-Control: no-store, no-cache, must-revalidate");
			header("Cache-Control: post-check=0, pre-check=0", false);
		}
	}

	/**
	* Play the flv  with lock
	*
	* @param string $key			Key Name
	* @param int $validtime			Defines how long time a key is valid
	* @param bool $usedb			Use Database key storage?
	* @return true/false			if sucess/failed.	
	*/
    function openLock($key=false,$validtime=30,$usedb=false)
    {
		if(!$key) return false;
		if(!session_id()) session_start();
		if($usedb) {
			include(FLV_INCLUDE_PATH."config.php");		
			
			$this->dbConnect();

			$result = mysql_list_tables ( $config['db_name'] ,$this->$db_link_id);

			$maketable = true;
			while ($row = mysql_fetch_row($result)) {
			   if($row[0] == $config['db_table'] ) $maketable = false;
			}
			if($maketable) {
				$query="CREATE TABLE `".$config['db_table']."` ( `uid` SMALLINT( 5 ) AUTO_INCREMENT , `sid` VARCHAR( 35 ) NOT NULL , `key` VARCHAR( 50 ) NOT NULL , `timeout` INT( 20 ) NOT NULL , `ip` VARCHAR( 15 ) NOT NULL , PRIMARY KEY ( `uid` ) )";
				if (!mysql_query($query)) die(mysql_error());
			}

			$sid = session_id();
			$expire = time()+$validtime;
			$ip = $_SERVER[REMOTE_ADDR];

			if (!mysql_query("INSERT INTO ".$config['db_table']." VALUES ('','$sid','$key','$expire','$ip')")) die(mysql_error());

			mysql_close($this->$db_link_id);
		} else {
			$_SESSION[FLV_SECRET_KEY][$key] = time()+$validtime;
		}
		return true;
	}
	
	/**
	* Play the flv  with lock
	*
	* @param int $lock_id			uid in Database
	*/
    function closeLock($lock_id = 0)
    {
		if($lock_id) {
			include(FLV_INCLUDE_PATH."config.php");
	
			$this->dbConnect();
	
			$sid = session_id();
			$timeout = time();
	
			$query = "DELETE FROM `".$config['db_table']."` WHERE `uid` = '$lock_id' AND `sid` LIKE '$sid' AND `key` LIKE '$this->lock_key'";
	
			if (!mysql_query($query)) die(mysql_error());
	
			mysql_close($this->$db_link_id);
		}
		unset($_SESSION[FLV_SECRET_KEY][$this->lock_key]);
	}

	/**
	* Play the flv  with lock
	*
	* @param bool $usedb			Check in database
	*/
    function validLock($usedb=false)
    {
		if(!session_id()) session_start();
		$return = false;
		if($usedb) {
			include(FLV_INCLUDE_PATH."config.php");
	
			$this->dbConnect();
	
			$sid = session_id();
			$timeout = time();
	
			$query = "SELECT uid FROM ".$config['db_table']." WHERE `sid` LIKE '$sid' AND `key` LIKE '$this->lock_key' AND `timeout` >= $timeout LIMIT 1";		
			$result = mysql_query($query);
			$row = mysql_fetch_assoc($result);
			$lock_id = $row['uid'];
			mysql_close($this->$db_link_id);
	
			if($lock_id) $return = true;
		} elseif($_SESSION[FLV_SECRET_KEY][$this->lock_key] >= time()) {
			$return = true;
		}
		$this->closeLock($lock_id);
		return $return;
	}
	
	/**
	* Play the flv  with lock
	*
	* @param string $key			Set Key
	*/
    function setKey($key)
    {
		$this->lock_key = $key;
	}	

    /**
	* Returns the next tag from the open file
	* 
	* @param array $skipTagTypes	The tag types contained in this array won't be examined
	* @return FLV_Tag_Generic or one of its descendants
	*/
    function getTag( $skipTagTypes = false )
    {
        if ($this->eof) return NULL;
        
        $hdr = fread( $this->fh, $this->TAG_HEADER_SIZE );
		
		if (strlen($hdr) < $this->TAG_HEADER_SIZE) {
		    $this->eof = true;
		   	return NULL;
		}

		// check against corrupted files
		$prevTagSize = unpack( 'Nprev', $hdr );

//		if ($prevTagSize['prev'] != $this->lastTagSize) die("<br>Previous tag size check failed. Actual size is ".$this->lastTagSize." but defined size is ".$prevTagSize['prev']);
		
		// Get the tag object by skiping the first 4 bytes which tell the previous tag size
		$tag = FLV_Tag::getTag( substr( $hdr, 4 ) );

		// Read at most MAX_TAG_BODY_SIZE bytes of the body
		$bytesToRead = min( $this->MAX_TAG_BODY_SIZE, $tag->size );
		$tag->setBody( fread( $this->fh, $bytesToRead ) );
		
		// Check if the tag body has to be processed
		if ( ( is_array($skipTagTypes) && !in_array( $tag->type, $skipTagTypes ) ) || !$skipTagTypes ) $tag->analyze();
		
		// If the tag was skipped or the body size was larger than MAX_TAG_BODY_SIZE
		if ($tag->size > $bytesToRead) fseek( $this->fh, $tag->size-$bytesToRead, SEEK_CUR );

		$this->lastTagSize = $tag->size + $this->TAG_HEADER_SIZE - 4;
		
		
		$tag->start = $this->getTagOffset();
		
		$tag->end = ftell($this->fh)+4;
		
		return $tag;
    }
    
	/**
	* Returns the offset from the start of the file of the last processed tag
	*
	* @return the offset
	*/
    function getTagOffset()
    {
    	return ftell($this->fh) - $this->lastTagSize;
    }
	
	/**
	* Connect to Database
	*/
    function dbConnect()
    {
		include(FLV_INCLUDE_PATH."config.php");		
		// connect to the mysql database server.
		$this->$db_link_id = mysql_connect ($config['db_host'], $config['db_username'], $config['db_password']);
		// sellect db		
		if (!mysql_select_db($config['db_name'])) die(mysql_error());
    }	
}

?>