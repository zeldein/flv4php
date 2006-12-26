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

  	var $fh;
    var $lastTagSize = 0;
    
    function FLV( $fname = false )
    {
		if($fname) {
			$this->open($fname);
			return true;
		}
		return false;
    }
	
	/*
	*
	* @param string $fname	Locatsion off file
	* @return true/false	if sucess/failed.
	*/
    function open( $fname = false )
    {
		if($fname) {
			$this->filename = $fname;
			$this->fh = @fopen( $fname, 'r' );
			if (!$this->fh) die('Unable to open the file');
		   
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

		if (strlen($hdr) < $this->TAG_HEADER_SIZE)
		{
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
		if ($tag->size > $bytesToRead)
		{
			fseek( $this->fh, $tag->size-$bytesToRead, SEEK_CUR );
		}
		
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
	* @param array $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param array $seekat			Start playback at..
	* @param array $newMetaData		Array off new metadata
	* @param array $merge			Merge original array with new one			
	*/
    function playFlv($limitSpeed = 0,$seekat = 0,$newMetaData = false,$merge = true)
    {
//		session_destroy();
		session_write_close();		
		$this->setHeader();
		
		header("Content-Disposition: filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));

		if($seekat != 0) {
			print(pack('N', 9 ));
	      	fseek($this->fh, $seekat);
		} else {
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
	* @param array $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param array $seekat			Start playback at..
	* @param array $newMetaData		Array off new metadata
	* @param array $merge			Merge original array with new one				
	*/
    function playFlvLock($limitSpeed = 0,$seekat = 0,$newMetaData = false,$merge = true)
    {
		if ( $_SESSION[FLV_SECRET_KEY] == true ) {
			$this->closeLock();
			$this->playFlv($limitSpeed,$seekat,$newMetaData,$merge);
		} else {
			$this->close();
			die(header("HTTP/1.0 404 Not Found"));
		}
	}
	
	/**
	* Get Flv Thumb output's a thumb clip from offset point, locate a keyframe and from there output's duration
	* if no keyframefouned it use the first keyframe.
	*
	* @param int $offset			Offset in ms
	* @param int $duratsion			Duratsion in ms
	*/
    function getFlvThumb($offset=2000,$duration=2000) {
//		session_destroy();
		session_write_close();
		$this->setHeader();
	
		header("Content-Disposition: filename=".basename($this->filename));		

		print("FLV");
		print(pack('C', 1 ));
		print(pack('C', 1 ));
		print(pack('N', 9 ));
		print(pack('N', 9 ));
		
		$this->start();
		while ($tag = $this->getTag($skipTagTypes)) {
			if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
				if (!$fhpos && $tag->timestamp >= $offset && $tag->frametype == 1 ) {
					$fhpos = $this->getTagOffset();
				} else if ($fhpos) {
					if ($tag->timestamp >= ($duration+$offset)) {
						$fhposend = $this->getTagOffset();
						break;
					}
				}
			}
			//Does it actually help with memory allocation?
			unset($tag);
		}

		if(!$fhposend) {
			$offset = $fhposend = $fhpos = $count = 0;
			$this->start();			
			while ($tag = $this->getTag($skipTagTypes)) {
				if ( $tag->type == FLV_TAG_TYPE_VIDEO ) {
					if (!$fhpos && $tag->timestamp >= $offset && $tag->frametype == 1 ) {
						$fhpos = $this->getTagOffset();
					} else if ($fhpos) {
						$count++;
						if ($tag->timestamp >= ($duration+$offset) && $count >= 1 ) {
							$fhposend = $this->getTagOffset();
							break;
						}
					}
				}
				//Does it actually help with memory allocation?
				unset($tag);
			}
		}
		
		fseek($this->fh, 0);
		fseek($this->fh, $fhpos);
		print(fread($this->fh, $fhposend));
		$this->close();		
	}
	
	/**
	* Download Flv
	*
	* @param array $limitSpeed		Limit speed off downloading, calculated off videorate + audiorate + $limitSpeed
	* @param array $newMetaData		Array off new metadata
	* @param array $merge			Merge original array with new one
	*/
    function downloadFlv($limitSpeed = 0,$newMetaData = false,$merge = true)
    {
//		session_destroy();
		session_write_close();	
		$this->setHeader();

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
	*/
    function setHeader()
    {
		header("Content-Type: video/x-flv");
		header("Content-Length: " .(string)(filesize($this->filename)) );
		// Date in the past
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		// always modified
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		// HTTP/1.1
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);	
	}

	/**
	* Play the flv  with lock
	*/
    function openLock()
    {
		session_start();
		$_SESSION[FLV_SECRET_KEY] = true;
	}
	
	/**
	* Play the flv  with lock
	*/
    function closeLock()
    {
		$_SESSION[FLV_SECRET_KEY] = false;
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
		
		if (strlen($hdr) < $this->TAG_HEADER_SIZE)
		{
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
		if ($tag->size > $bytesToRead) {
			fseek( $this->fh, $tag->size-$bytesToRead, SEEK_CUR );		
		}

		$this->lastTagSize = $tag->size + $this->TAG_HEADER_SIZE - 4;
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
}

?>