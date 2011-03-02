<?php
require_once("lib/xml2json.php");

//*** Start Configuration Options ***
$amazonAWSAccessKeyId = "PUT_YOUR_AMAZON_AWS_ACCESS_KEY_ID";
$amazonSecretAccessKey = "PUT_YOUR_AMAZON_SECRECT_ACCESS_KEY";
$amazonAssociateTag = "PUT_YOUR_ASSOCIATE_TAG";

$amazonItemMax = 1;     //-1 = all items
//*** End Configurations Options ***

//QueryString Options
//q       : [Requred] search item (can either be UPS,ISBN,keyword, or category)
//type    : [Optional] http://www.amazon.com/gp/seller/asin-upc-isbn-info.html
//search  : [Optional] Amazon search index option (http://docs.amazonwebservices.com/AWSEcommerceService/4-0/ApiReference/SearchIndexValues.html)
//num     : [Optional] Return number of items (if omitted, the default value will be used. set -1 to get all possible num. of items)
//output  : [Optional] xml/json/raw (default: raw)

//URL QueryStrings Format Examples
// http://example.com/amazon.php?q=vizio
// http://example.com/amazon.php?q=vizio&output=json
// http://example.com/amazon.php?q=9780764547164
// http://example.com/amazon.php?q=9780764547164&type=UPC
// http://example.com/amazon.php?q=9780764547164&type=ISBN&search=books
// http://example.com/amazon.php?q=9780764547164&type=ISBN&search=books&num=3

function amazonEncode($text)
{
    $encodedText = "";
    $j = strlen($text);
    for($i=0;$i<$j;$i++)
    {
        $c = substr($text,$i,1);
        if (!preg_match("/[A-Za-z0-9\-_.~]/",$c))
        {
            $encodedText .= sprintf("%%%02X",ord($c));
        }
        else
        {
            $encodedText .= $c;
        }
    }
    return $encodedText;
}

function amazonSign($url,$secretAccessKey)
{
    // 0. Append Timestamp parameter
    $url .= "&Timestamp=".gmdate("Y-m-d\TH:i:s\Z");

    // 1a. Sort the UTF-8 query string components by parameter name
    $urlParts = parse_url($url);
    parse_str($urlParts["query"],$queryVars);
    ksort($queryVars);

    // 1b. URL encode the parameter name and values
    $encodedVars = array();
    foreach($queryVars as $key => $value)
    {
        $encodedVars[amazonEncode($key)] = amazonEncode($value);
    }

    // 1c. 1d. Reconstruct encoded query
    $encodedQueryVars = array();
    foreach($encodedVars as $key => $value)
    {
        $encodedQueryVars[] = $key."=".$value;
    }
    $encodedQuery = implode("&",$encodedQueryVars);

    // 2. Create the string to sign
    $stringToSign  = "GET";
    $stringToSign .= "\n".strtolower($urlParts["host"]);
    $stringToSign .= "\n".$urlParts["path"];
    $stringToSign .= "\n".$encodedQuery;

    // 3. Calculate an RFC 2104-compliant HMAC with the string you just created,
    //    your Secret Access Key as the key, and SHA256 as the hash algorithm.
    if (function_exists("hash_hmac"))
    {
        $hmac = hash_hmac("sha256",$stringToSign,$secretAccessKey,TRUE);
    }
    elseif(function_exists("mhash"))
    {
        $hmac = mhash(MHASH_SHA256,$stringToSign,$secretAccessKey);
    }
    else
    {
        die("No hash function available!");
    }

    // 4. Convert the resulting value to base64
    $hmacBase64 = base64_encode($hmac);

    // 5. Use the resulting value as the value of the Signature request parameter
    // (URL encoded as per step 1b)
    $url .= "&Signature=".amazonEncode($hmacBase64);

    return $url;
}

function amazonItemPrint($data, $max)
{
    // MagicParser_parse($url,"myAmazonRecordHandler","xml|ITEMSEARCHRESPONSE/ITEMS/ITEM/");
    // end the table

    $output = "";
    $xml = simplexml_load_string($data);
    $xml_root = new SimpleXMLElement('<Result></Result>');

    //checking for an error
    if ($xml->Items->Request->Errors)
    {
        xml_join($xml_root,$xml->Items->Request->Errors);
        return $xml_root->asXML();
    }

    $dxml = $xml_root->addChild('Data');
    if($xml->Items->Item){
        $x = 0;
        foreach ($xml->Items->Item as $item){
            if ($max < 0 || $x++ < $max){
                xml_join($dxml,$item->ItemAttributes->Title);
                xml_join($dxml,$item->ItemAttributes->ListPrice->FormattedPrice);
                if ($item->EditorialReviews->EditorialReview)
                {
                    $review = $item->EditorialReviews->EditorialReview;
                    xml_join($dxml,$review->Source);
                    $dxml = $dxml->addChild($review->Content->getName(),strip_tags($review->Content));
                }
            }
        }
    }
    return $xml_root->asXML();
}

function xml_join($root, $append) {
    if ($append) {
        if (strlen(trim((string) $append))==0) {
            $xml = $root->addChild($append->getName());
            foreach($append->children() as $child) {
                xml_join($xml, $child);
            }
        } else {
            $xml = $root->addChild($append->getName(), (string) $append);
        }
        foreach($append->attributes() as $n => $v) {
            $xml->addAttribute($n, $v);
        }
    }
}

if (!isset($_GET["q"])) { exit; }
else { $q_item = $_GET["q"]; }

$q_output = isset($_GET["output"])?$_GET["output"]:null;
$q_type = isset($_GET["type"])?$_GET["type"]:null;
$q_search = isset($_GET["search"])?$_GET["search"]:"Blended";
$q_num = isset($_GET["num"])?$_GET["num"]:$amazonItemMax;

if ($q_item)
{
    // start the table
    // construct Amazon Web Services REST Query URL
    $url  = "http://webservices.amazon.com/onca/xml?Service=AWSECommerceService";
    //  $url .= "&Version=2009-03-01";
    $url .= "&Operation=ItemSearch";
    $url .= "&AWSAccessKeyId=".$amazonAWSAccessKeyId;
    $url .= "&AssociateTag=".$amazonAssociateTag;
    $url .= "&ResponseGroup=Medium";
    $url .= "&SearchIndex=" . $q_search;

    if ($q_type)
    {
        $url .= "&IdType=" . $q_type;
        $url .= "&ItemId=" . $q_item;
        $url .= "&Keywords=" . urlencode($q_item);
    }
    else
    {
        $url .= "&Keywords=" . urlencode($q_item);
    }

    // sign
    $url = amazonSign($url,$amazonSecretAccessKey);
    // fetch the response and parse the results
    $curl_handle=curl_init();
    curl_setopt($curl_handle,CURLOPT_URL,$url);
    curl_setopt($curl_handle,CURLOPT_CONNECTTIMEOUT,2);
    curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER,1);
    $data = curl_exec($curl_handle);

    if ($q_output == "raw" || $q_output == null)
    {
        header ("Content-Type:text/xml");
        echo $data;
    }
    elseif ($q_output == "xml")
    {
        header ("Content-Type:text/xml");
        echo amazonItemPrint($data,$q_num);
    }
    elseif ($q_output == "json")
    {
        header ("Content-Type:application/json");
        echo xml2json::transformXmlStringToJson(amazonItemPrint($data,$q_num));
    }
}
