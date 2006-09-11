<?php

/*
 * Simple test script which analyzes a FLV and creates .meta file with the results for
 * its use with the sample player. It'll also output the obtained meta data for debugging
 * purposes.
 */

define('FILENAME', 'test.flv');
define('AUDIO_FRAME_INTERVAL', 3);

$start = microtime(true);

include_once 'FLV/FLV.php';


$flv = new FLV();

try {
	$flv->open( FILENAME );
} catch (Exception $e) {
	die("<pre>The following exception was detected while trying to open a FLV file:\n" . $e->getMessage() . "</pre>");
}
	

$meta = array();
$meta['metadatacreator'] = 'FLV Tools for PHP v0.1 by DrSlump';
$meta['metadatadate'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
$meta['keyframes'] = array();
$meta['keyframes']['filepositions'] = array();
$meta['keyframes']['times'] = array();

$skipTagTypes = array();

try {
	while ($tag = $flv->getTag( $skipTagTypes ))
	{
	    $ts = number_format($tag->timestamp/1000, 3);
	    
	    if ($tag->timestamp > 0)
		    $meta['lasttimestamp'] = $ts;
	    
	    switch ($tag->type)
	    {
	        case FLV_Tag::TYPE_VIDEO :
	        	        	
	           	//Optimization, extract the frametype without analyzing the tag body
	           	if ((ord($tag->body[0]) >> 4) == FLV_Tag_Video::FRAME_KEYFRAME)
	           	{
					$meta['keyframes']['filepositions'][] = $flv->getTagOffset();
					$meta['keyframes']['times'][] = $ts;
	           	}
	           	
	            if ( !in_array(FLV_TAG::TYPE_VIDEO, $skipTagTypes) )
	            {
	                $meta['width'] = $tag->width;
	                $meta['height'] = $tag->height;
	                $meta['videocodecid'] = $tag->codec;                
	            	array_push( $skipTagTypes, FLV_Tag::TYPE_VIDEO );
	            }
	            
	        	break;
	        	
	        case FLV_Tag::TYPE_AUDIO :
	        	
	        	if ($ts - $oldTs > AUDIO_FRAME_INTERVAL)
	        	{
		        	$meta['audioframes']['filepositions'][] = $flv->getTagOffset();
		        	$meta['audioframes']['times'][] = $ts;
		        	$oldTs = $ts;
	        	}
	        	
	            if ( !in_array( FLV_Tag::TYPE_AUDIO, $skipTagTypes) )  
	            {
		            $meta['audiocodecid'] = $tag->codec;
		            $meta['audiofreqid'] = $tag->frequency;
		            $meta['audiodepthid'] = $tag->depth;
		            $meta['audiomodeid'] = $tag->mode;
		            
	            	array_push( $skipTagTypes, FLV_Tag::TYPE_AUDIO );
	            }
	        break;
	        case FLV_Tag::TYPE_DATA :
	            if ($tag->name == 'onMetaData')
	            {
	            	$fileMetaPos = $pos;
	            	$fileMetaSize = $tag->size;
	            	$fileMeta = $tag->value;
	            }
	        break;
	    }
	    
	    //Does it actually help with memory allocation?
	    unset($tag);
	}
}
catch (Exception $e)
{
	echo "<pre>The following error took place while analyzing the file:\n" . $e->getMessage() . "</pre>";
	$flv->close();
	die(1);
}

$flv->close();


$end = microtime(true);
echo "<hr/>PROCESS TOOK " . number_format(($end-$start), 2) . " seconds<br/>";


if (! empty($meta['keyframes']['times']))
	$meta['lastkeyframetimestamp'] = $meta['keyframes']['times'][ count($meta['keyframes']['times'])-1 ];
	
$meta['duration'] = $meta['lasttimestamp'];

echo "<pre>"; print_r($meta); echo "</pre>";


//if the metadata is pressent in the file merge it with the generated one
if (!empty($fileMeta))
{
	$meta = array_merge( $fileMeta, $meta );
}

//serialize the metadata as an AMF stream
include_once 'FLV/Util/AMFSerialize.php';
$amf = new FLV_Util_AMFSerialize();

$serMeta = $amf->serialize('onMetaData');
$serMeta.= $amf->serialize($meta);

echo "LEN: " . strlen($serMeta) . "<br/>";

$out = pack('NNN', $flv->bodyOfs, $fileMetaPos, $fileMetaSize);
$out.= pack('C', FLV_Tag::TYPE_DATA );
$out.= pack('Cn', 0, strlen($serMeta)); //assumes it's shorter than 64Kb
$out.= pack('N', 0);
$out.= pack('Cn', 0, 0);
$out.= $serMeta;
file_put_contents( FILENAME . '.meta', $out );


/*
include_once 'FLV/Util/AMFUnserialize.php';

$amfser = new FLV_Util_AMFSerialize();
$data = $amfser->serialize( $meta );

$amf = new AMFUnserialize( $data );
$data = $amf->getItem();

echo "<hr/><hr/><pre>"; print_r($data); echo "</pre>";
*/
?>