<?php

/*
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

 * Usage:
 * http://tools.wmflabs.org/fiwiki-tools/finna/finna_to_commonscats.php?url=https%3A%2F%2Fwww.finna.fi%2FRecord%2Fhkm.HKMS000005%3Akm0000n7pw

*/

$output_started=false;

function get_json($url)
{
	$file=file_get_contents($url);
	$ret=json_decode($file, true);
	return $ret;
}

// This just glues values from array to a plaintext string,
function get_value($list,$all, $mandatory, $key, $qualifier_key, $skip_qual)
{
	$ret="";
	$delim="";
	if (count($list)>1 && $all==0) {
		 print_error_and_exit("ERROR: get_value: incorrect number of values " . json_encode($list));
	}
	foreach ($list as $l)
	{
		$qual_str="";
		if (isset($list[$qualifier_key]) 
			&& trim($l[$qualifier_key])!="" 
			&& trim($l[$qualifier_key]) != $skip_qual
			&& $skip_qual!="*"
		)
		{
			$qual_str=$l[$qualifier_key] .": " ;
		}

		$ret.=$qual_str . $l[$key] . $delim;
		$delim="; "; 
	}
	if ($mandatory!=0 && $ret=="")
	{
		 print_error_and_exit("ERROR: get_value() no value found");
	}
	return $ret;
}

// Get information for the keyword from Finto
// Finto is a Finnish thesaurus and ontology service
// and we are trying to get plural and non plural translations 
// for the keyword in Finnish, Swedish and English 
function finto_get_subject($name)
{

	// First we search the exact terms

	$url="http://api.finto.fi/rest/v1/search?query=" . urlencode($name);
	$json=get_json($url);

	$term_url="";
	if (count($json['results']) == 0 )
	{
		return array();
	}

	// Search YSO (default Finto vocabulary)
	foreach($json['results'] as $r)
	{
		if ($r['vocab']!="yso") continue;
		$term_url=$r['uri'];
		$term_vocab=$r['vocab'];
	}

	// If there is no YSO result then use the  first one
	if ($term_url=="") {
		$term_url=$json['results'][0]['uri'];
		$term_vocab=$r['vocab'];
	}

	// Search detailed information for the term
	$url="http://api.finto.fi/rest/v1/".$term_vocab ."/data?format=application/json&uri=" . urlencode($term_url);
	$json=get_json($url);
	$ret=array();

	foreach ($json['graph'] as $graph)
	{

		if ($graph['uri']==$term_url)
		{
			if (isset($graph["prefLabel"])) $ret["prefLabel"]=$graph["prefLabel"];
			if (isset($rgraph["altLabel"])) $ret["altLabel"]=$graph["altLabel"];

			if (isset($graph["http://www.yso.fi/onto/yso-meta/singularPrefLabel"]))
			{
				$ret["singularPrefLabel"]=$graph["http://www.yso.fi/onto/yso-meta/singularPrefLabel"];
			}
		}
	}
	return $ret;
}

// We are fetching the P373 (Commonscat) for the title in given language from Wikidata
function get_wikidata_commonscat($title, $language)
{
	$ret=array();
	$encoded_title=urlencode(str_replace(" ", "_", $title));
	$url="https://www.wikidata.org/w/api.php?action=wbgetentities&format=json&sites=". $language. "wiki&titles=" .$encoded_title . "&languages=" . $language;
	$json=get_json($url);
	foreach($json['entities'] as $row)
	{
		if (isset($row['claims']) && isset($row['claims']['P373']))
		{
			foreach ($row['claims']['P373'] as $cat)
			{
				if (isset($cat['mainsnak']['datavalue']['value']))
					array_push($ret, $cat['mainsnak']['datavalue']['value']);
			}
		}
	}
	return $ret;
}

// Get Wikimedia Commons categories for the keywords
function get_commonscats($subjects)
{
	$category=array();
	foreach($subjects as $subject)
	{
		// Get alternative names for the keyword from Finto
		$finto=finto_get_subject($subject[0]);
		foreach($finto as $name_type)
		{
			foreach ($name_type as $name)
			{
				// Title is case sensitive so we are just blindly bruteforcing 
				$category=array_merge($category, get_wikidata_commonscat($name['value'], $name['lang']));
				$category=array_merge($category, get_wikidata_commonscat(lcfirst($name['value']), $name['lang']));
				$category=array_merge($category, get_wikidata_commonscat(ucfirst($name['value']), $name['lang']));
			}
		}
	}
	// Remove duplicate categories
	$category=array_unique($category);
	return $category;
}

// If kuva is from Helsinkikuvia.fi get link which points there for a better resolution image
// if not then use normal Finna image url
function parse_image_url($finna_id, $finna_image)
{

	$ret="";
	if (preg_match("|hkm.HKMS|", $finna_id, $m))
	{ 
		$url="https://api.finna.fi/v1/record?id=".$finna_id."&field[]=fullRecord";
		$file_fullRecord=file_get_contents($url);

		if (preg_match("|<linkResource>(.*?)<.*?linkResource><|ism", $file_fullRecord, $m))
		{
			$file_url= str_replace("\\", "", $m[1]);
			$file_url=str_replace("preview", "org", $file_url);
			$file_url=str_replace("&amp;", "&", $file_url);
			$ret=$file_url;
		}
		else
		{
			print_error_and_exit("helsinkikuvia url parsing failed: " . $file);
		}
	}

	else
	{
		// Normal Finna url
		$ret="https://www.finna.fi" . $finna_image;
	}
	return $ret;
}

function parse_finna_id($url)
{
	$finna_id="";
	if (preg_match("|https:\/\/www.helsinkikuvia.fi\/record\/(.*?)\/|ism", $url, $m))
	{
		$finna_id=$m[1];

	}
	elseif (preg_match("|https:\/\/www.helsinkikuvia.fi\/record\/(.*?)\z|ism", $url, $m))
	{
		$finna_id=$m[1];

	}
	elseif (preg_match("/https:\/\/(hkm.|www.)?finna.fi\/Record\/(.*?)(#|\/|\z|\&)/ism", $url, $m))
	{
		$finna_id=$m[2];
	}
	else
	{
		print_error_and_exit("ERROR: unknown id " . $finna_id . " : " . $url);
	}
	return $finna_id;
}

/* -----------------------------------------------------------------------

 HTML OUTPUT

--------------------------------------------------------------------------*/


function print_html_header()
{
	global $output_started;
	if ($output_started==false)
		print "<html><head><meta charset='UTF-8'><title>Finna tool</title></head><body>";
	$output_started=true;
}
function print_html_footer()
{
	print "</body></html>";
}

function print_html_form()
{
	$commons_url="https://commons.wikimedia.beta.wmflabs.org/w/index.php?title=Special:ListFiles/Zache-test&ilshowall=1";
	$finna_url="https://www.finna.fi/Search/Results?filter%5B%5D=%7Eformat%3A%220%2FImage%2F%22&filter%5B%5D=online_boolean%3A%221%22&filter%5B%5D=%7Eusage_rights_str_mv%3A%22usage_B%22&filter%5B%5D=%7Ebuilding%3A%220%2FHKM%2F%22&lookfor=Vappu&type=AllFields&view=grid";
	
	print_html_header();
	$str= "<center style='margin-top:5em'>";
	$str.= "<form method='GET'>Finna URL: <input type='text' name='url' size='70' /><input type=submit value='Upload'/></form>";
	$str.= "<div style='text-align:left'>";
	$str.= "Usage:"; 
	$str.= "<ul>";
	$str.= "<li>Tool is tested only with <a href='$finna_url'>Helsinki City  Museum pictures</a> (available metadata is tested only with these)</li>";
	$str.= "<li>Select a photo and view detailed information of the photo (Page with url is like https://www.finna.fi/Record/hkm.HKMS000005:km0000n7pw )</li></ul>";
	$str.= "</div>";
	$str.= "</center>";
	print $str;
	print_source_code();
	print_html_footer();
}

function print_error_and_exit($str)
{
       header('Content-Type: application/json');
       $out=array('error'=> $str);
       print json_encode($out);
       die(1);
}

function print_msg($str)
{
	print "<pre>";
	print_r($str);
	print "</pre>\n";
	flush();
}

function print_source_code()
{
	$str= "<hr>";
	$str.= "Source code:";
	$str.= "<ul>";
	$str.= "<li><a href='finna_to_commonscats.php.txt'>Finna_to_commonscats.php</a></li>";
	$str.= "<li><a href='botclasses.php.txt'>botclasses.php</a> (copied from <a href='https://en.wikipedia.org/wiki/User:RMCD_bot/botclasses.php'>https://en.wikipedia.org/wiki/User:RMCD_bot/botclasses.php</a> )</li>";
	$str.= "</ul>";
	$str.= "Contact:";
	$str.= "<ul>";
	$str.= "<li><a href='https://meta.wikimedia.org/wiki/User:Zache'>Kimmo Virtanen (Zache)</a></li>";
	$str.= "</ul>";
	print $str;

}

/* -----------------------------------------------------------------------

 Parsing input data

--------------------------------------------------------------------------*/


if (trim($_GET['url'])!="")
{
	// $url="https://www.finna.fi/Record/hkm.HKMS000005:000000l5";
	$url_param=trim($_GET['url']);
}
else
{
	// If no url-parameter then show info and basic upload form
	print_html_form();
	die(1);
}

$finna_id=parse_finna_id($url_param);
$url="https://api.finna.fi/v1/record?id=" . $finna_id;
$r=get_json($url);
$record=$r['records'][0];

// Check if the image exists with suitable rights
if ($r['status']!="OK") print_error_and_exit("id not found");
if ($r['resultCount']!=1) print_error_and_exit("resultCount = " . $r['resultCount']);
if ($record['formats'][0]['translated']!="Kuva") print_error_and_exit("format = " . $record['formats'][0]['translated']);
if ($record['imageRights']['copyright']!="CC BY 4.0") print_error_and_exit("imageRights = " . $record['imageRights']['copyright']);
if (!isset($record['images'][0])) print_error_and_exit("images not set");


// Reading data from Finna result
$out=array();
$out['image']=$record['images'][0];
$out['title']=$record['title'];
$out['author']=get_value($record['nonPresenterAuthors'], 1, 1, 'name', 'role', 'Valokuvaaja');
$out['copyright']=$record['imageRights']['copyright'];
$out['buildings']=get_value($record['buildings'], 0, 1, 'translated', 'value', '*');
$out['id']=$record['id'];
$out['subjects']=$record['subjects'];
$out['commonscats']=get_commonscats($record['subjects']);

// Get some extra information from Finna
$url="https://api.finna.fi/v1/record?field[]=events&field[]=summary&id=" . $finna_id;
$r1=get_json($url);
$record_ext=$r1['records'][0];

$out['summary']=implode("; ", $record_ext['summary']);
$out['date']=get_value($record_ext['events']['valmistus'], 1, 1, 'date', 'type', 'valmistus');
$out['file_url']=parse_image_url($finna_id, $out['image']);

header('Content-Type: application/json');
print json_encode($out);

?>

