<?php
/**
 * Created by PhpStorm.
 * User: chanaka.bandararatna
 * Date: 2018/06/06
 * Time: 22:51
 */


ini_set('display_errors', 'On');
error_reporting(E_ALL);
require_once('php5/KalturaClient.php');
ini_set ( "memory_limit", "1024M" );

//require_once ('php5ott/KalturaClient.php');

if ($argc < 7)
{
    die('Usage - php addMediaAndMeta.php 1982541 a16d34b92e6764a4baf71244d1333996 metafile.txt tagfile.txt picfile.txt medaProfileId distProfileId[optional]');
}

var_dump($argv);

$version="3.3.0";
$partnerId = $argv[1];  //
$adminSecret = $argv[2];
$metadataFileName = $argv[3]; //'metafile.txt';
$tagdataFileName = $argv[4]; //'tagfile.txt';
$picdataFileName = $argv[5]; //'picfile.txt';
$metadataProfileId = $argv[6]; //
if ($argc==6) $distProfId = $argv[7];//

date_default_timezone_set('UTC');

if (!file_exists($metadataFileName))
{
    die("metadata file doesn't exists" . PHP_EOL);
}
if (!file_exists($tagdataFileName))
{
    die("tagdata file doesn't exists" . PHP_EOL);
}
if (!file_exists($picdataFileName))
{
    die("picdata file doesn't exists" . PHP_EOL);
}

// make session
$config = new KalturaConfiguration($partnerId);
$config->serviceUrl = 'http://www.kaltura.com/';
$client = new KalturaClient($config);
$ks = $client->session->start($adminSecret, null, KalturaSessionType::ADMIN, $partnerId, null, null );
echo $ks;
$client->setKs($ks);

$filter = new KalturaAssetFilter();
$pager = new KalturaFilterPager();

// read the meta data file ///
$metadataFileContent = file_get_contents($metadataFileName);
//var_dump($metadataFileContent);
$metadataRows = explode("\n",$metadataFileContent);
var_dump($metadataRows);
$metaMap = [];
foreach($metadataRows as $row) {
    $metas = explode("\t", $row);
    $count=count($metas);
    if ($count>1) {
        $key = $metas[0];
    } else {
        echo "empty metadata row";
        echo PHP_EOL;
        continue;
    }
    for($c=1; $c<$count;$c++){
        $metaMap[$key][$c-1]=$metas[$c];
    }
}
var_dump($metaMap);

////// read the tags file ///////
$tagdataFileContent = file_get_contents($tagdataFileName);
//var_dump($metadataFileContent);
$tagdataRows = explode("\n",$tagdataFileContent);
var_dump($tagdataRows);
$tagMap = [];
foreach($tagdataRows as $row) {
    $tags = explode("\t", $row);
    $count=count($tags);
    if ($count==3) {
        $tagMap[$tags[0]][$tags[1]] = $tags[2];
    } else {
        echo "invalid tag row";
        echo PHP_EOL;
        continue;
    }
}
var_dump($tagMap);

////// read the pic file ///////
$picdataFileContent = file_get_contents($picdataFileName);
//var_dump($metadataFileContent);
$picdataRows = explode("\n",$picdataFileContent);
var_dump($picdataRows);
$picMap = [];
foreach($picdataRows as $row) {
    $pics = explode("\t", $row);
    $count=count($pics);
    if ($count>1) {
        $key = $pics[0];
    } else {
        echo "empty pic row";
        echo PHP_EOL;
        continue;
    }
    for($c=1; $c<$count;$c++){
        $picMap[$key][$c-1]=$pics[$c];
    }
}
var_dump($picMap);

////// Algorithm/////
/// For each {mid,metas} pair of metaMap
///     Retrieve metas
///     Map by mid and retrieve tags from tagMap {mid,tag}
///     Map by mid and retrieve pics from picMap {mid, pic}
///     Create new entry with metas, tags and pics
$metaMapCount=count($metaMap);
$tagMapCount=count($tagMap);
$picMapCount=count($picMap);
var_dump($metaMapCount);
var_dump($tagMapCount);
var_dump($picMapCount);


foreach($metaMap as $mid => $metas) {
    //var_dump($mid);
    //var_dump($metas);
try {
    echo "mid=" . $mid . " |";
    $tags = null;
    // && array_key_exists($mid,$tagMap)
    if (isset($tagMap[$mid])) {
        $tags = $tagMap[$mid];
    }
    //var_dump($tags);

    $pics = null;
    if (isset($picMap[$mid])) {
        $pics = $picMap[$mid];
    }
    //var_dump($pics);

    // check if co_guid (referenceId) exists in KMC.
    $filterMediaEntry = new KalturaMediaEntryFilter();
    $filterMediaEntry->referenceIdEqual = getValue($metas[2]);
    //var_dump(getValue($metas[2]));
    $pager = null;
    $result = $client->media->listAction($filterMediaEntry, $pager);
    //var_dump($result);
    if ($result->{'totalCount'} > 0) {
        echo "referenceId=" . getValue($metas[2]) . "ALREADY Exists " . PHP_EOL;
        continue;
    }


    // Add new entry
    try {
        $newentry = new KalturaMediaEntry();
        $newentry->name = getValue($metas[0]); //metas[0]
        $newentry->description = getValue($metas[1]); // metas[1]
        $newentry->startDate = getValueTime($metas[17]) ? getValueTime($metas[17]) : time(); // epoch(metas[17]) or if null use time()
        if (getValueTime($metas[18])) $newentry->endDate = getValueTime($metas[18]); // epcho (metas[18]) or if null don't set
        $newentry->type = KalturaEntryType::MEDIA_CLIP; // fixed
        $newentry->mediaType = KalturaMediaType::VIDEO; // fixed
        $newentry->referenceId = getValue($metas[2]); // metas[2]
        $result = $client->media->add($newentry);
        $newentryId = $result->{'id'};
        //var_dump($newentry);
        echo "entryId=" . $newentryId . " |";
    }
    catch (Exception $e){
        echo "error=" . $e->getMessage(). " |";
    }
    // Create thumbnailAsset from the url and add
    try{
        if (!empty(getValue($pics[0]))) {
            $thumbAsset = new KalturaThumbAsset();
            $result = $client->thumbAsset->add($newentryId, $thumbAsset);
            $thumbAssetId = $result->{'id'};
            $contentResource = new KalturaUrlResource();
            $contentResource->url = getValue($pics[0]); // 16:9 pics[0]
            $result = $client->thumbAsset->setContent($thumbAssetId, $contentResource);
            echo "thumb added= " . $thumbAssetId . " |";
        } else {
            echo "thumb not added |";
        }
    }
    catch(Exception $e){
        echo "thumb failed= " . $e->getMessage() . " |";
    }


    // Set metadata now
    try {
        //$metadataProfileId = 7685711; //TODO param
        $objectType = KalturaMetadataObjectType::ENTRY;
        $objectId = $newentryId;
        $xmlData = getMetaAndTags($metas, $tags, $pics); // creates the meta
        $metadataPlugin = KalturaMetadataClientPlugin::get($client);
        $result = $metadataPlugin->metadata->add($metadataProfileId, $objectType, $objectId, $xmlData);
        echo "metadata xml=". $xmlData . "st=" . $result->{'status'} . " |";
    } catch (Exception $e) {
        echo "error=" . $e->getMessage() . " |";
    }
    //var_dump($result);


    try {
        if (!empty($distProfId)) {
            //$distProfId = 1619091;
            $entryDistribution = new KalturaEntryDistribution();
            $entryDistribution->distributionProfileId = $distProfId;
            $contentdistributionPlugin = KalturaContentdistributionClientPlugin::get($client);
            $filterDist = new KalturaEntryDistributionFilter();
            $filterDist->distributionProfileIdEqual = $distProfId;
            $entryDistribution->entryId = $newentryId;
            $entry = $contentdistributionPlugin->entryDistribution->add($entryDistribution);
            $result = $contentdistributionPlugin->entryDistribution->submitAdd($entry->id);
            echo "dist :" . $result->{'status'} . " |";
        } else {
            echo "dist :0 |";
        }
    } catch (Exception $e) {
        echo "dist :" . $e->getMessage() . " |";
    }
    echo PHP_EOL;
}
catch(Exception $e){
    echo "entry failed ex=" . $e . PHP_EOL;
}

}

exit(0);

echo "index | status | flavorID to add | results";
echo PHP_EOL;

// Add new entry
$newentry = new KalturaMediaEntry();
$newentry->name = 'chanaka_v5'; //meta[1]
$newentry->description = 'def2'; // meta[2]
$newentry->startDate = time(); // epoch(meta[18]) or if null use time()
$newentry->endDate=time()+10000; // epcho (meta[19]) or if null don't set
$newentry->type = KalturaEntryType::MEDIA_CLIP; // fixed
$newentry->mediaType = KalturaMediaType::VIDEO; // fixed
$newentry->referenceId = 'chanaka_v5'; // meta[3]
$result = $client->media->add($newentry);
$newentryId= $result->{'id'};
var_dump($result);

// Create thumbnailAsset from the url and add
$thumbAsset= new KalturaThumbAsset();
$result=$client->thumbAsset->add($newentryId,$thumbAsset);
$thumbAssetId=$result->{'id'};
$contentResource = new KalturaUrlResource();
$contentResource->url='http://vfes-images-eus1.ott.kaltura.com/viacomIN/85dbbc69c6154878b0e53c05ec0bb413_1280X720.jpg'; // 16:9 pics[0]
$result = $client->thumbAsset->setContent($thumbAssetId, $contentResource);

// Set metadata now
$metadataProfileId = 7685711;
$objectType = KalturaMetadataObjectType::ENTRY;
$objectId = $newentryId;
$xmlData = getMetadata(); // creates the meta
$metadataPlugin = KalturaMetadataClientPlugin::get($client);
$result = $metadataPlugin->metadata->add($metadataProfileId, $objectType, $objectId, $xmlData);
var_dump($result);

// Distribute the media (test)
$distProfId=1619091;
$entryDistribution = new KalturaEntryDistribution();
$entryDistribution->distributionProfileId = $distProfId;
$contentdistributionPlugin = KalturaContentdistributionClientPlugin::get($client);
$filterDist = new KalturaEntryDistributionFilter();
$filterDist->distributionProfileIdEqual = $distProfId;
$entryDistribution->entryId = $newentryId;
$entry = $contentdistributionPlugin->entryDistribution->add( $entryDistribution );
$result=$contentdistributionPlugin->entryDistribution->submitAdd( $entry->id  );
var_dump($result);

function getMetadata(){

    // sample
    /*
"<metadata>
							<MediaType>Series</MediaType>
							<GEOBlockRule>India_only</GEOBlockRule>
							<WatchPermissionRule>Parent allowed</WatchPermissionRule>
							<STRINGLanguage>Hindi</STRINGLanguage>
							<STRINGSeriesSynopsis>'Big' Buck wakes up in his rabbit hole.</STRINGSeriesSynopsis>
							<STRINGCensor/>
							<STRINGSeriesShortTitle>Rabbit</STRINGSeriesShortTitle>
							<STRINGSeriesSecondaryTitle>The end</STRINGSeriesSecondaryTitle>
							<STRINGSeriesMainTitle>V18TEST_S5</STRINGSeriesMainTitle>
							<STRINGSBU>COH</STRINGSBU>
							<STRINGSeries_Cover_photo/>
							<NUMSeason>05</NUMSeason>
							<NUMYearofRelease>2008</NUMYearofRelease>
							<BOOLOnAir>True</BOOLOnAir>
							<OTTTAGKeywords/>
							<OTTTAGGenre>Reality</OTTTAGGenre>
							<OTTTAGContributorList>Sacha Goedegebure</OTTTAGContributorList>
							<OTTTAGAwardList/>
							<OTTTAGMediaExternalId/>
							<STRINGISACTIVE>True</STRINGISACTIVE>
							<STRINGPicUrl2>https://peach.blender.org/wp-content/uploads/poster_rodents_big.jpg</STRINGPicUrl2>
							<STRINGPicUrl3>https://peach.blender.org/wp-content/uploads/poster_bunny_big.jpg</STRINGPicUrl3>
							<OTTTAGAge>12</OTTTAGAge>
							<STRINGAudioDefaultLanguage>Swahi</STRINGAudioDefaultLanguage>
						</metadata>"
*/

$MediaType='Series'; // TBD
$GEOBlockRule='India_only'; //　fixed
$WatchPermissionRule='Parent allowed'; // fixed
$STRINGLanguage='Hindi'; // metas[2]
$STRINGSeriesSynopsis='\'Big\' Buck wakes up in his rabbit hole.'; // metas[3]
$STRINGCensor=''; // metas[4]
$STRINGSeriesShortTitle='Rabbit'; // metas[5]
$STRINGSeriesSecondaryTitle='The end'; // metas[6]
$STRINGSeriesMainTitle='V18TEST_S5'; // metas[7]
$STRINGSBU='COH'; // metas[8]
$STRINGSeries_Cover_photo=''; // metas[9]
$NUMSeason='05'; // metas[12]
$NUMYearofRelease='2008'; // metas[13]
$BOOLOnAir='True'; // if (metas[15]==1) true
$OTTTAGKeywords='';  // tags[1522]
$OTTTAGGenre='Reality'; // tags[1523]
$OTTTAGContributorList='Sacha Goedegebure'; // tags[1524]
$OTTTAGAwardList=''; // tags[1525]
$OTTTAGMediaExternalId=''; // tags[1543]
$STRINGISACTIVE='True'; //fixed
$STRINGPicUrl2='http://vfes-images-eus1.ott.kaltura.com/viacomIN/85dbbc69c6154878b0e53c05ec0bb413_1024X768.jpg'; // 4:3 pics[1]
$STRINGPicUrl3='http://vfes-images-eus1.ott.kaltura.com/viacomIN/85dbbc69c6154878b0e53c05ec0bb413_1024X768.jpg'; // 2:3 pics[1]
$OTTTAGAge='12'; // tags[12078]
$STRINGAudioDefaultLanguage='Hindi'; // metas[10]


/*
 * 1522,Keywords
1523,Genre
1524,ContributorList
1525,AwardList
1543,MediaExternalId
12078,Age
 * */
    $xml=
        "<metadata> <MediaType>$MediaType</MediaType> <GEOBlockRule>$GEOBlockRule</GEOBlockRule> <WatchPermissionRule>$WatchPermissionRule</WatchPermissionRule> <STRINGLanguage>$STRINGLanguage</STRINGLanguage> <STRINGSeriesSynopsis>$STRINGSeriesSynopsis</STRINGSeriesSynopsis> <STRINGCensor>$STRINGCensor</STRINGCensor> <STRINGSeriesShortTitle>$STRINGSeriesShortTitle</STRINGSeriesShortTitle> <STRINGSeriesSecondaryTitle>$STRINGSeriesSecondaryTitle</STRINGSeriesSecondaryTitle> <STRINGSeriesMainTitle>$STRINGSeriesMainTitle</STRINGSeriesMainTitle> <STRINGSBU>$STRINGSBU</STRINGSBU> <STRINGSeries_Cover_photo>$STRINGSeries_Cover_photo</STRINGSeries_Cover_photo> <NUMSeason>$NUMSeason</NUMSeason><NUMYearofRelease>$NUMYearofRelease</NUMYearofRelease><BOOLOnAir>$BOOLOnAir</BOOLOnAir> <OTTTAGKeywords>$OTTTAGKeywords</OTTTAGKeywords> <OTTTAGGenre>$OTTTAGGenre</OTTTAGGenre><OTTTAGContributorList>$OTTTAGContributorList</OTTTAGContributorList> <OTTTAGAwardList>$OTTTAGAwardList</OTTTAGAwardList> <OTTTAGMediaExternalId>$OTTTAGMediaExternalId</OTTTAGMediaExternalId> <STRINGISACTIVE>$STRINGISACTIVE</STRINGISACTIVE> <STRINGPicUrl2>$STRINGPicUrl2</STRINGPicUrl2> <STRINGPicUrl3>$STRINGPicUrl3</STRINGPicUrl3> <OTTTAGAge>$OTTTAGAge</OTTTAGAge> <STRINGAudioDefaultLanguage>$STRINGAudioDefaultLanguage</STRINGAudioDefaultLanguage> </metadata>";

    return $xml;

}

function getValue($input)
{
    if (isset($input))
    {
        return !empty($input)?$input:'';
    }
    else
        return '';
}

function getValueBool($input)
{
    if (!empty($input) and $input>0)
        return 'True';
    else
        return 'False';
}

function getValueTime($input)
{
    // input 2016-01-27 16:57:00:000
    if (strtotime($input)!==false)
        return strtotime($input);
    else
        return null;
}

function getMetaAndTags($metas, $tags, $pics){

    // sample
    /*
"<metadata>
							<MediaType>Series</MediaType>
							<GEOBlockRule>India_only</GEOBlockRule>
							<WatchPermissionRule>Parent allowed</WatchPermissionRule>
							<STRINGLanguage>Hindi</STRINGLanguage>
							<STRINGSeriesSynopsis>'Big' Buck wakes up in his rabbit hole.</STRINGSeriesSynopsis>
							<STRINGCensor/>
							<STRINGSeriesShortTitle>Rabbit</STRINGSeriesShortTitle>
							<STRINGSeriesSecondaryTitle>The end</STRINGSeriesSecondaryTitle>
							<STRINGSeriesMainTitle>V18TEST_S5</STRINGSeriesMainTitle>
							<STRINGSBU>COH</STRINGSBU>
							<STRINGSeries_Cover_photo/>
							<NUMSeason>05</NUMSeason>
							<NUMYearofRelease>2008</NUMYearofRelease>
							<BOOLOnAir>True</BOOLOnAir>
							<OTTTAGKeywords/>
							<OTTTAGGenre>Reality</OTTTAGGenre>
							<OTTTAGContributorList>Sacha Goedegebure</OTTTAGContributorList>
							<OTTTAGAwardList/>
							<OTTTAGMediaExternalId/>
							<STRINGISACTIVE>True</STRINGISACTIVE>
							<STRINGPicUrl2>https://peach.blender.org/wp-content/uploads/poster_rodents_big.jpg</STRINGPicUrl2>
							<STRINGPicUrl3>https://peach.blender.org/wp-content/uploads/poster_bunny_big.jpg</STRINGPicUrl3>
							<OTTTAGAge>12</OTTTAGAge>
							<STRINGAudioDefaultLanguage>Swahi</STRINGAudioDefaultLanguage>
						</metadata>"
*/

    $MediaType=isset($metas[16])?getValue($metas[16]):'';//  metas[16] conversion.

    $GEOBlockRule=isset($metas[21])?getValue($metas[21]):'';//  metas[21] conversion.//'India_only'; //　fixed
    $WatchPermissionRule=isset($metas[20])?getValue($metas[20]):'';//metas[20]'Parent allowed'; // fixed
    $STRINGLanguage=isset($metas[3])?getValue($metas[3]):''; // metas[3]
    $STRINGSeriesSynopsis=isset($metas[4])?getValue($metas[4]):'';  // metas[4]
    $STRINGCensor=isset($metas[5])?getValue($metas[5]):''; // metas[5]
    $STRINGSeriesShortTitle=isset($metas[6])?getValue($metas[6]):''; // metas[6]
    $STRINGSeriesSecondaryTitle=isset($metas[7])?getValue($metas[7]):''; // metas[7]
    $STRINGSeriesMainTitle=isset($metas[8])?getValue($metas[8]):''; // metas[8]
    $STRINGSBU=isset($metas[9])?getValue($metas[9]):'';// metas[9]
    $STRINGSeries_Cover_photo=isset($metas[10])?getValue($metas[10]):''; // metas[10]
    $NUMSeason=isset($metas[12])?getValue($metas[12]):''; // metas[12]
    $NUMYearofRelease=isset($metas[13])?getValue($metas[13]):''; // metas[13]
    $BOOLOnAir=isset($metas[15])?getValueBool($metas[15]):''; // if (metas[15]==1) true
    //var_dump($BOOLOnAir);
    $OTTTAGKeywords=isset($tags[1522])?getValue($tags[1522]):'';  // tags[1522]
    $OTTTAGGenre=isset($tags[1523])?getValue($tags[1523]):'';; // tags[1523]
    $OTTTAGContributorList=isset($tags[1524])?getValue($tags[1524]):''; // tags[1524]
    $OTTTAGAwardList=isset($tags[1525])?getValue($tags[1525]):''; // tags[1525]
    $OTTTAGMediaExternalId=isset($tags[1543])?getValue($tags[1543]):''; // tags[1543]
    $STRINGISACTIVE='True'; //fixed
    $STRINGPicUrl2=isset($pics[1])?getValue($pics[1]):''; // 4:3 pics[1]
    $STRINGPicUrl3=isset($pics[1])?getValue($pics[1]):''; // 2:3 pics[1]
    $OTTTAGAge=isset($tags[12078])?getValue($tags[12078]):'';// tags[12078]
    $OTTTAGName=isset($tags[12189])?getValue($tags[12189]):'';// tags[12189]
    $STRINGAudioDefaultLanguage=isset($metas[11])?getValue($metas[11]):''; // metas[11]


    /*
     * 1522,Keywords
    1523,Genre
    1524,ContributorList
    1525,AwardList
    1543,MediaExternalId
    12078,Age
    12189,Name
     * */
//    $xml=
//        "<metadata> <MediaType>$MediaType</MediaType> <GEOBlockRule>$GEOBlockRule</GEOBlockRule> <WatchPermissionRule>$WatchPermissionRule</WatchPermissionRule> <STRINGLanguage>$STRINGLanguage</STRINGLanguage> <STRINGSeriesSynopsis>$STRINGSeriesSynopsis</STRINGSeriesSynopsis> <STRINGCensor>$STRINGCensor</STRINGCensor> <STRINGSeriesShortTitle>$STRINGSeriesShortTitle</STRINGSeriesShortTitle> <STRINGSeriesSecondaryTitle>$STRINGSeriesSecondaryTitle</STRINGSeriesSecondaryTitle> <STRINGSeriesMainTitle>$STRINGSeriesMainTitle</STRINGSeriesMainTitle> <STRINGSBU>$STRINGSBU</STRINGSBU> <STRINGSeries_Cover_photo>$STRINGSeries_Cover_photo</STRINGSeries_Cover_photo> <NUMSeason>$NUMSeason</NUMSeason><NUMYearofRelease>$NUMYearofRelease</NUMYearofRelease><BOOLOnAir>$BOOLOnAir</BOOLOnAir> <OTTTAGKeywords>$OTTTAGKeywords</OTTTAGKeywords> <OTTTAGGenre>$OTTTAGGenre</OTTTAGGenre><OTTTAGContributorList>$OTTTAGContributorList</OTTTAGContributorList> <OTTTAGAwardList>$OTTTAGAwardList</OTTTAGAwardList> <OTTTAGMediaExternalId>$OTTTAGMediaExternalId</OTTTAGMediaExternalId> <STRINGISACTIVE>$STRINGISACTIVE</STRINGISACTIVE> <STRINGPicUrl2>$STRINGPicUrl2</STRINGPicUrl2> <STRINGPicUrl3>$STRINGPicUrl3</STRINGPicUrl3> <OTTTAGAge>$OTTTAGAge</OTTTAGAge> <STRINGAudioDefaultLanguage>$STRINGAudioDefaultLanguage</STRINGAudioDefaultLanguage> <OTTTAGName>$OTTTAGName</OTTTAGName> </metadata>";

    $xml="<metadata> <MediaType>$MediaType</MediaType> ";
    if (!empty($GEOBlockRule))
        $xml .= "<GEOBlockRule>$GEOBlockRule</GEOBlockRule> ";
    if (!empty($WatchPermissionRule))
        $xml .= "<WatchPermissionRule>$WatchPermissionRule</WatchPermissionRule> ";
    $xml .= "<STRINGLanguage>$STRINGLanguage</STRINGLanguage> <STRINGSeriesSynopsis>$STRINGSeriesSynopsis</STRINGSeriesSynopsis> <STRINGCensor>$STRINGCensor</STRINGCensor> <STRINGSeriesShortTitle>$STRINGSeriesShortTitle</STRINGSeriesShortTitle> <STRINGSeriesSecondaryTitle>$STRINGSeriesSecondaryTitle</STRINGSeriesSecondaryTitle> <STRINGSeriesMainTitle>$STRINGSeriesMainTitle</STRINGSeriesMainTitle> <STRINGSBU>$STRINGSBU</STRINGSBU> <STRINGSeries_Cover_photo>$STRINGSeries_Cover_photo</STRINGSeries_Cover_photo> <NUMSeason>$NUMSeason</NUMSeason><NUMYearofRelease>$NUMYearofRelease</NUMYearofRelease><BOOLOnAir>$BOOLOnAir</BOOLOnAir> <OTTTAGKeywords>$OTTTAGKeywords</OTTTAGKeywords> <OTTTAGGenre>$OTTTAGGenre</OTTTAGGenre><OTTTAGContributorList>$OTTTAGContributorList</OTTTAGContributorList> <OTTTAGAwardList>$OTTTAGAwardList</OTTTAGAwardList> <OTTTAGMediaExternalId>$OTTTAGMediaExternalId</OTTTAGMediaExternalId> <STRINGISACTIVE>$STRINGISACTIVE</STRINGISACTIVE> <STRINGPicUrl2>$STRINGPicUrl2</STRINGPicUrl2> <STRINGPicUrl3>$STRINGPicUrl3</STRINGPicUrl3> <OTTTAGAge>$OTTTAGAge</OTTTAGAge> <STRINGAudioDefaultLanguage>$STRINGAudioDefaultLanguage</STRINGAudioDefaultLanguage> <OTTTAGName>$OTTTAGName</OTTTAGName> </metadata>";

    return $xml;

}

function getMediaTypeString($id){



}
