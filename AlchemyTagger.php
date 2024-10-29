<?php
/*
Plugin Name: AlchemyTagger
Plugin URI: http://www.alchemyapi.com/tools/alchemytagger/
Description: Auto-tag your blog posts with people, locations, companies, and other named entities.  Uses the AlchemyAPI.com tagging service.
Author: Orchestr8, LLC
Author URI: http://www.alchemyapi.com/
Version: 1.1.4
*/

require("AlchemyAPI.php");

add_action('admin_menu', 'AlchemyAPI_Setup');

add_action('wp_ajax_AlchemyAPI_TagPost', 'AlchemyAPI_TagPost');

add_action('run_dummy', 'AlchemyAPI_Dummy');

function AlchemyAPI_ParseEntityResponse($result)
{
	$doc = simplexml_load_string($result);

	$entities = $doc->xpath("//entity");

	$returnEntityArr = array();
	$returnRelevanceArr = array();
	foreach ($entities as $key => $value)
	{
        	$typeArr = $doc->xpath("/results/entities/entity[$key+1]/type");
	        $textArr = $doc->xpath("/results/entities/entity[$key+1]/text");
			$relevanceArr = $doc->xpath("/results/entities/entity[$key+1]/relevance");

	        if (count($typeArr) > 0 && count($textArr) > 0 && strlen($typeArr[0]) > 0)
        	{
	                $type = "$typeArr[0]";
        	        $text = "$textArr[0]";
					$relevance = $relevanceArr[0];
					
	                $disambArr = $doc->xpath("/results/entities/entity[$key+1]/disambiguated/name");
        	        if (count($disambArr) > 0 && strlen($disambArr[0]) > 0)
                	{
						$doDisamb = get_option('alchemyapi-disamb-enabled');
						if (strlen($doDisamb) < 1)
						{
							$doDisamb = "true";
						}

						if ($doDisamb == "true")
						{
		                        $text = "$disambArr[0]";
						}
					}
					//$relevance = ((float)$relevance );
					//$returnEntityArr[] = ($text ." (". $relevance . ")");
					$returnEntityArr[] = $text;
					$returnRelevanceArr[] = (float)$relevance;
	        }
	}

	return array($returnEntityArr,$returnRelevanceArr);
}

function AlchemyAPI_ParseConceptResponse($result)
{
	$doc = simplexml_load_string($result);
	
	$concepts = $doc->xpath("/results/concepts/concept");

	$returnConceptArr = array();
	$returnRelevanceArr = array();
	foreach ($concepts as $key => $value)
	{
			$textArr = $doc->xpath("//results/concepts/concept[$key+1]/text");
        	$text = $textArr[0];
			$relevanceArr = $doc->xpath("//results/concepts/concept[$key+1]/relevance");
			
			if (count($textArr) > 0 && count($relevanceArr) > 0 && $text!="") {
				$relevance = $relevanceArr[0];
				//$returnConceptArr[]=($text . " (" . $relevance . ")");
				$returnConceptArr[] = $text;
				$returnRelevanceArr[]=(float)$relevance;
			}
	}
	return array($returnConceptArr,$returnRelevanceArr);
}

function AlchemyAPI_CheckAPIKey()
{
	$apiKey = get_option('alchemyapi-key');
	if (strlen($apiKey) < 1)
	{
		return "false";
	}

	// Create an AlchemyAPI object.
	$alchemyObj = new AlchemyAPI();

	//$alchemyObj->_apiKey = $apiKey;
	$alchemyObj->setApiKey($apiKey);

	$postContent = "This is an API key validation check.";

	try
	{
		$result = $alchemyObj->TextGetRankedNamedEntities($postContent);
	}
	catch (Exception $e)
	{
		return $e;
	}

	return "true";
}

function AlchemyAPI_TagPost()
{
	$apiKey = get_option('alchemyapi-key');
	if (strlen($apiKey) < 1)
	{
		die("You must first configure an AlchemyAPI Access Key from within your plugins settings screen.");
	}

	// Create an AlchemyAPI object.
	$alchemyObj = new AlchemyAPI();

	//$alchemyObj->_apiKey = $apiKey;
	$alchemyObj->setApiKey($apiKey);

	$postContent = stripslashes($_POST['text']);
	$currentTags = $_POST['currentTags'];
	
	$entityResult = "";
	$conceptResult = "";

	$error = false;
	$errorMsg = "";
	try
	{
		if(get_option('alchemyapi-extract-mode') == 'named_entities' || get_option('alchemyapi-extract-mode') == 'both'){
			$entityParams = new AlchemyAPI_NamedEntityParams();
			$entityParams->setCustomParameters("atCurrentTags",$currentTags);
			$entityResult = $alchemyObj->HTMLGetRankedNamedEntities($postContent, "http://www.alchemyapi.com/tools/alchemytagger/?type=post", "xml",$entityParams);
		}
		if(get_option('alchemyapi-extract-mode') == 'concepts' || get_option('alchemyapi-extract-mode') == 'both'){
			$conceptParams = new AlchemyAPI_ConceptParams();
			$conceptParams->setCustomParameters("atCurrentTags",$currentTags);
			$conceptResult = $alchemyObj->HtmlGetRankedConcepts($postContent, "http://www.alchemyapi.com/tools/alchemytagger/?type=post", "xml", $conceptParams);
		}
	}
	catch (Exception $e)
	{
		$error = true;
		$errorMsg = $e->getMessage();
	}
	if ($error == true)
	{
		if ("Enter some text to analyze." == $errorMsg)
		{
			die("Type some text first.");
		}
		else if ("Error making API call: unsupported-text-language" == $errorMsg)
		{
			die("Type some text first.");
		}
		else if( "Error making API call: invalid-api-key" == $errorMsg) {
			die("Check your API key!");
		}
		else
		{
			//die($errorMsg);
			die($errorMsg);
		}
	}
	else {
		$count = 0;
		if(get_option('alchemyapi-extract-mode') == 'named_entities' || get_option('alchemyapi-extract-mode') == 'both'){
			list($entityResultArr,$entityRelevanceArr)=AlchemyAPI_ParseEntityResponse($entityResult);
			$count += count($entityResultArr);
		}
		if(get_option('alchemyapi-extract-mode') == 'concepts' || get_option('alchemyapi-extract-mode') == 'both'){
			list($conceptResultArr,$conceptRelevanceArr)=AlchemyAPI_ParseConceptResponse($conceptResult);
			$count += count($conceptResultArr);
		}
		
		if ($count < 1)
		{
			echo(" ");
			die("No tag suggestions at this time.");
		}
	}
	
	if(get_option('alchemyapi-extract-mode') == 'named_entities') {
		$resultArr = $entityResultArr;
		$relevanceArr = $entityRelevanceArr;
	}
	else if(get_option('alchemyapi-extract-mode') == 'concepts') {
		$resultArr = $conceptResultArr;
		$relevanceArr = $conceptRelevanceArr;
	}
	else if(get_option('alchemyapi-extract-mode') == 'both') {
		$diff_array = array_diff($entityResultArr, $conceptResultArr);
		$relevanceArr =array();
		$resultArr =array();
		foreach ($diff_array as $mKey => $mValue) { 
			$relevanceArr[] = $entityRelevanceArr[$mKey];
			$resultArr[] = $mValue;
		}
		$relevanceArr  = array_merge($relevanceArr, $conceptRelevanceArr);
		$resultArr = array_merge($resultArr, $conceptResultArr);
	}
	
	array_multisort($relevanceArr, SORT_DESC, $resultArr);
	
	$outTable = "{\"tags\":[";

	$firstT = true;
	foreach ($resultArr as $typeKey => $typeVal)
	{
		if ($firstT == false)
		{
			$outTable = "$outTable,";
		}
		$firstT = false;
		$outTable = "$outTable{\"text\":\"$typeVal\",\"relevance\":\"".$relevanceArr[$typeKey]."\"}";

	}

	$outTable = "$outTable]}";
		
	
	//header('Content-type: application/json');
	echo "$outTable\n";
	
}


function AlchemyAPI_Setup()
{
	if (function_exists('add_meta_box'))
	{
		$mode = 'normal';
		$guiMode = get_option('alchemyapi-gui-mode');
		if (strlen($guiMode) > 0 && $guiMode == "advanced")
		{
			$mode = 'advanced';
		}
		$priority = 'low';
		$replaceGui = get_option('alchemyapi-replace-tag-gui');
		if (strlen($replaceGui) < 1 || $replaceGui == "true")
		{
			$priority = 'high';
		}

		add_meta_box('alchemyapi', 'AlchemyTagger', 'AlchemyAPI_GUI', 'post', $mode, $priority);
	} 
	else
	{
		add_action('dbx_post_sidebar', 'AlchemyAPI_GUI', 1);
	}

	add_submenu_page('plugins.php', 'AlchemyTagger Configuration', 'AlchemyTagger Configuration',
			 'manage_options', 'alchemytagger-config', 'AlchemyAPI_Config');
}

function AlchemyAPI_Config()
{
	if (isset($_POST['alchemyapi-key']))
	{
		$thisKey = $_POST['alchemyapi-key'];
		$thisKey = trim($thisKey);

		update_option('alchemyapi-key', $thisKey);

		if (isset($_POST['alchemyapi-gui-mode']))
		{
			update_option('alchemyapi-gui-mode', $_POST['alchemyapi-gui-mode']);
		}
		else
		{
			update_option('alchemyapi-gui-mode', 'normal');
		}
		if (isset($_POST['alchemyapi-replace-tag-gui']))
		{
			update_option('alchemyapi-replace-tag-gui', $_POST['alchemyapi-replace-tag-gui']);
		}
		else
		{
			update_option('alchemyapi-replace-tag-gui', 'false');
		}
		if (isset($_POST['alchemyapi-disamb-enabled']))
		{
			update_option('alchemyapi-disamb-enabled', $_POST['alchemyapi-disamb-enabled']);
		}
		else
		{
			update_option('alchemyapi-disamb-enabled', 'false');
		}
		if (isset($_POST['alchemyapi-extract-mode']))
		{
			update_option('alchemyapi-extract-mode', $_POST['alchemyapi-extract-mode']);
		}
		else
		{
			update_option('alchemyapi-extract-mode', 'both');
		}
		if (isset($_POST['alchemyapi-strict-keywords']))
		{
			update_option('alchemyapi-strict-keywords', $_POST['alchemyapi-strict-keywords']);
		}
		else
		{
			update_option('alchemyapi-strict-keywords', 'false');
		}
		if (isset($_POST['alchemyapi-async-enabled']))
		{
			update_option('alchemyapi-async-enabled', $_POST['alchemyapi-async-enabled']);
		}
		else
		{
			update_option('alchemyapi-async-enabled', 'false');
		}
		if (isset($_POST['alchemyapi-async-timeout']))
		{
			$timeout = $_POST['alchemyapi-async-timeout'];
			$asyncTimeout = (int)$_POST['alchemyapi-async-timeout'];
			if ($asyncTimeout < 10)
			{
				$timeout = "10";
			}
			if ($asyncTimeout > 120)
			{
				$timeout = "120";
			}
			update_option('alchemyapi-async-timeout', $timeout);
		}
		else
		{
			update_option('alchemyapi-async-timeout', '10');
		}
	}
	?>

	<div class="wrap">
	<h2>AlchemyTagger Configuration</h2>
	<div class="narrow">
	<form action="" method="post" id="alchemyapi-config" style="">

	<p>AlchemyTagger automatically works in the background as you're writing new blog posts, analyzing your writing and suggesting useful categorization tags.  Tags make your posts easier to navigate, better-ranked by search engines, and more!
	</p>

	<p>AlchemyTagger requires an API key to operate. To create an API key, <a href="http://www.alchemyapi.com/api/register.html" target="_blank">click here</a>.</p>
	
	<p>
		<label for="alchemyapi-key"><b><font size="+1">AlchemyAPI.com API Key</font></b></label><br />
	<?php

	$isValid = false;
	$key = get_option('alchemyapi-key');
	if (strlen($key) > 0)
	{
			$isValid = AlchemyAPI_CheckAPIKey();
	}
	$extractMode = get_option('alchemyapi-extract-mode');
	if (strlen($extractMode) < 1)
	{
		update_option('alchemyapi-extract-mode', 'both');
	}
	$disambOn = get_option('alchemyapi-extract-mode');
	if (strlen($disambOn) < 1)
	{
		update_option('alchemyapi-disamb-enabled', 'true');
	}
	$asyncOn = get_option('alchemyapi-async-enabled');
	if (strlen($asyncOn) < 1)
	{
		update_option('alchemyapi-async-enabled', 'true');
	}
	$strictKeywords = get_option('alchemyapi-strict-keywords');
	if (strlen($strictKeywords) < 1)
	{
		update_option('alchemyapi-strict-keywords', 'true');
	}
	$asyncTimeout = get_option('alchemyapi-async-timeout');
	if (strlen($asyncTimeout) < 1)
	{
		update_option('alchemyapi-async-timeout', '10');
	}
	$guiMode = get_option('alchemyapi-gui-mode');
	if (strlen($guiMode) < 1)
	{
		update_option('alchemyapi-gui-mode', 'normal');
	}
	$guiMode = get_option('alchemyapi-replace-tag-gui');
	if (strlen($guiMode) < 1)
	{
		update_option('alchemyapi-replace-tag-gui', 'true');
	}
	if( $isValid == "true" )
	{
		?>
		<div style="width: 300px; background:#00ff00; border: 1px solid #000000;">
		API Key is valid!
		</div>
		<?php
	}
	else if(strpos($isValid,fopen))
	{
		?>
		<div style="width:300px; background:#ff0000; border: 1px solid #000000;">
		Could not reach alchemyapi server.  Make sure allow_url_fopen = On in your php.ini file.
		<br>
		
		</div>
		<?php
	}
	else
	{
		?>
		<div style="width:300px; background:#ff0000; border: 1px solid #000000;">
		API Key is *not* valid
		
		</div>
		<?php
	}
	
	?>
		<table><tr><td>
		<input type="text" name="alchemyapi-key" value="<?php echo get_option('alchemyapi-key'); ?>" />
		</td><td>(<a href="http://www.alchemyapi.com/api/register.html">What is this?</a>)
		</td></tr></table>
	</p>
	
	<p>
		<label>&nbsp;&nbsp;Extract Tags Using: &nbsp;&nbsp;	</label>
		<input type="radio" value="named_entities" name="alchemyapi-extract-mode" <?php if (get_option('alchemyapi-extract-mode') == 'named_entities') { echo "checked=\"checked\""; } ?> >Named Entities</input>&nbsp;&nbsp;
		<input type="radio" value="concepts" name="alchemyapi-extract-mode"  <?php if (get_option('alchemyapi-extract-mode') == 'concepts') { echo "checked=\"checked\""; } ?> >Concepts</input>&nbsp;&nbsp;
		<input type="radio" value="both" name="alchemyapi-extract-mode" <?php if (get_option('alchemyapi-extract-mode') == 'both') { echo "checked=\"checked\""; } ?> >Both</input>	&nbsp;&nbsp;
	
	</p>

	<p>
		<label><input value="true" <?php if (get_option('alchemyapi-replace-tag-gui') == 'true') { echo "checked=\"checked\""; } ?> type="checkbox" name="alchemyapi-replace-tag-gui" />&nbsp;&nbsp;Replace the default WordPress tagging interface.</label>
	</p>

	<!--<p>
		<label><input value="advanced" <?php if (get_option('alchemyapi-gui-mode') == 'advanced') { echo "checked=\"checked\""; } ?> type="checkbox" name="alchemyapi-gui-mode" />&nbsp;&nbsp;Display AlchemyTagger under 'Advanced Options' on 'Write' page.</label>
	</p>
	-->

	<p>
		<label><input value="true" <?php if (get_option('alchemyapi-disamb-enabled') == 'true') { echo "checked=\"checked\""; } ?> type="checkbox" name="alchemyapi-disamb-enabled" />&nbsp;Enable tag disambiguation.</label>
		(<a href="http://www.alchemyapi.com/api/entity/disamb.html">What is this?</a>)
	</p>
	
	<!--
	<p>
		<label><input value="true" <?php if (get_option('alchemyapi-strict-keywords') == 'true') { echo "checked=\"checked\""; } ?> type="checkbox" name="alchemyapi-strict-keywords" />&nbsp;&nbsp;Enable strict keyword extraction.</label>
	</p>
	-->

	<p>
		<label><input value="true" <?php if (get_option('alchemyapi-async-enabled') == 'true') { echo "checked=\"checked\""; } ?> type="checkbox" name="alchemyapi-async-enabled" />&nbsp;&nbsp;Enable automatic tag suggestions (tags appear as you type).</label>
	</p>

	<p>
		<table><tr><td>
		Automatic tag suggestions polling frequency (in seconds):
		</td><td>
		<input type="text" name="alchemyapi-async-timeout" value="<?php echo get_option('alchemyapi-async-timeout'); ?>" />
		</td><td><i>(Enter a value from 10-120)</i>
		</td></tr></table>
	</p>

	<p class="submit">
		<input type="submit" value="Update Settings" />
	</p>
		
	</form>
	</div>
	</div>

	<?php	
}

function AlchemyAPI_InsertJS()
{
	if ( ! defined( 'WP_CONTENT_URL' ) )
	      define( 'WP_CONTENT_URL', get_option( 'siteurl' ) . '/wp-content' );
	if ( ! defined( 'WP_CONTENT_DIR' ) )
	      define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	if ( ! defined( 'WP_PLUGIN_URL' ) )
	      define( 'WP_PLUGIN_URL', WP_CONTENT_URL. '/plugins' );
	if ( ! defined( 'WP_PLUGIN_DIR' ) )
	      define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );

	?>
	<script type="text/javascript" src="<?php $var = WP_PLUGIN_URL.'/AlchemyTagger/webtoolkit.scrollabletable.js'; echo $var; ?>"></script>
	<script type="text/javascript" src="<?php $var = WP_PLUGIN_URL.'/AlchemyTagger/webtoolkit.jscrollable.js'; echo $var; ?>"></script>
	<?php
}

function AlchemyAPI_GUI()
{
	AlchemyAPI_InsertJS();

	$asyncEnabled = get_option('alchemyapi-async-enabled');
	if (strlen($asyncEnabled) < 1)
	{
		$asyncEnabled = "true";
	}
	$asyncTimeout = get_option('alchemyapi-async-timeout');
	$timeout = 10;
	if (strlen($asyncTimeout) > 0)
	{
		$timeout = (int)$asyncTimeout;
	}
	$replaceGui = get_option('alchemyapi-replace-tag-gui');
	if (strlen($replaceGui) < 1 || $replaceGui == "true")
	{
		?>
		<script type="text/javascript">
		//<![CDATA[

		jQuery(document).ready( function() {
			jQuery('#tagsdiv').hide();
			jQuery('#tagsdiv-post_tag').hide();
			
			AlchemyAPI_ShowActiveTags();
		<?php
	if ($asyncEnabled == "true")
	{
		?>
			window.setTimeout('AlchemyAPI_AsyncUpdate();', 1500);
		<?php
	}
		?>
			});
		//]]>
		</script>
		<?php
	}

	?>
	<script type="text/javascript">
	//<![CDATA[

	var g_AlchemyAPI_SuggestionsVisible = false;
	var g_userClickedSuggest = false;

	function AlchemyAPI_GetPost()
	{

		var l_form = document.getElementById('post');

		if (typeof tinyMCE != 'undefined' &&
		    typeof tinyMCE.selectedInstance != 'undefined' && 
		    !tinyMCE.selectedInstance.spellcheckerOn)
		{
			if (typeof tinyMCE.triggerSave == 'function')
			{
				tinyMCE.triggerSave();
			}
			else
			{
				tinyMCE.wpTriggerSave();
			}	
		}
		return l_form.content.value;
	}
	
	function AlchemyAPI_Dummy() {
		alert("in dummy");
	}
	
	function AlchemyAPI_TagPost(a_isUser)
	{
		//do_action('run_dummy');
		//	alert("trying action");
		//AlchemyAPI_TagPost();
		//alert("done action");
		var call_url = '<?php bloginfo( 'wpurl' );?>/wp-admin/admin-ajax.php';
		jQuery.post('<?php bloginfo( 'wpurl' );?>/wp-admin/admin-ajax.php',
			    {text: AlchemyAPI_GetPost(),
				 currentTags: AlchemyAPI_GetTagsVal(),
			     action: 'AlchemyAPI_TagPost',
			     cookie: document.cookie},
			    AlchemyAPI_DisplayTags);
		
	}

	var g_lastAsyncPostText = "";

	function AlchemyAPI_TagPostAsync()
	{
		var l_text = AlchemyAPI_GetPost();

		if (l_text != g_lastAsyncPostText)
		{
			g_lastAsyncPostText = l_text;

			g_userClickedSuggest = false;

			jQuery.post('<?php bloginfo( 'wpurl' );?>/wp-admin/admin-ajax.php',
				    {text: l_text,
					 currentTags: AlchemyAPI_GetTagsVal(),
				     action: 'AlchemyAPI_TagPost',
				     cookie: document.cookie},
				    AlchemyAPI_DisplayTags);
		}
	}

	var g_lastAsyncTime = 0;

	function AlchemyAPI_AsyncUpdate()
	{
		<?php
		if ($asyncEnabled == "true")
		{
		?>
		var l_date = new Date();

		if ((l_date.getTime() / 1000) > ((g_lastAsyncTime / 1000) + <?php echo $timeout; ?>))
		{
			g_lastAsyncTime = l_date.getTime();

			AlchemyAPI_TagPostAsync();
		}
		window.setTimeout('AlchemyAPI_AsyncUpdate();', 1500);
		<?php
		}
		?>
	}

	function AlchemyAPI_TrimStr(a_str)
	{
		return a_str.replace(/^\s\s*/, '').replace(/\s\s*$/, '');
	}
	
	function AlchemyAPI_AddTag(a_tagArr, a_tag)
	{
		var l_found = false;
		for (var k = 0;k < a_tagArr.length;++k)
		{
			if (a_tagArr[k].toLowerCase() == AlchemyAPI_TrimStr(a_tag.toLowerCase()))
			{
				l_found = true;
				break;
			}
		}

		if (l_found == false)
		{
			a_tagArr[a_tagArr.length] = AlchemyAPI_TrimStr(a_tag);
		}

		return a_tagArr;
	}

	function AlchemyAPI_IsTagActive(a_tag)
	{
		var l_tag = AlchemyAPI_TrimStr(a_tag);

		
		var l_tags = AlchemyAPI_GetTagsVal();
			
		var l_tagArr = l_tags.split(',');
		var l_newTagArr = new Array();
		for (var i = 0;i < l_tagArr.length;++i)
		{
			l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);

			if (l_tagArr[i].toLowerCase() == a_tag.toLowerCase())
			{
				return true;
			}
		}
		return false;
	}

	function AlchemyAPI_RemoveSingleTag(a_tag)
	{
		var l_tag = AlchemyAPI_TrimStr(a_tag);

		
		var l_tags = AlchemyAPI_GetTagsVal();

		var l_tagArr = l_tags.split(',');

		var l_newTagArr = new Array();

		for (var i = 0;i < l_tagArr.length;++i)
		{
			l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);

			if (l_tagArr[i] != a_tag)
			{
				l_newTagArr[l_newTagArr.length] = l_tagArr[i];
			}
		}

		var l_tagText = "";
		for (var i = 0;i < l_newTagArr.length;++i)
		{
			if (l_tagText.length > 0) l_tagText += ",";
			l_tagText += l_newTagArr[i];
		}

		AlchemyAPI_SetTagsVal(l_tagText);

		if (typeof tag_update_quickclicks == 'function')
		{
			tag_update_quickclicks();
		}

		AlchemyAPI_DisplayTagSuggestions();

		AlchemyAPI_ShowActiveTags();
	}
	

	function AlchemyAPI_GetTagsVal() {
		var retVal = "";
		if( jQuery('#tags-input').length )
		{
			retVal += jQuery('#tags-input').val();
		}

		if(  jQuery('.the-tags').length )
		{
			var tmp = "," + retVal;
			var tmpArr = jQuery('.the-tags').val().split(",");
			var i;
			for( i = 0; i<tmpArr.length; i++ )
			{
				if( tmp.indexOf(tmpArr[i]) == -1 )
				{
					if( retVal != "" )
						retVal += ",";
					retVal += tmpArr[i];
				}
			}
		}
		return retVal;
	}
	
	function AlchemyAPI_SetTagsVal(l_tagText) {
		
		if( jQuery('#tags-input').length )
		{
			jQuery('#tags-input').val(l_tagText);
		}
		if(  jQuery('.the-tags').length )
		{
			jQuery('.the-tags').val(l_tagText);
		}
	}

	function AlchemyAPI_AddSingleTag(a_tag, a_mode)
	{
		var l_tag = AlchemyAPI_TrimStr(a_tag);

		var l_tags = AlchemyAPI_GetTagsVal();
		var l_tagArr = l_tags.split(',');
		for (var i = 0;i < l_tagArr.length;++i)
		{
			l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);
		}

		AlchemyAPI_AddTag(l_tagArr, l_tag);

		var l_tagText = "";
		for (var i = 0;i < l_tagArr.length;++i)
		{
			if (l_tagText.length > 0) l_tagText += ",";
			l_tagText += l_tagArr[i];
		}
		AlchemyAPI_SetTagsVal(l_tagText);
		try {
			if (typeof tagBox.quickClicks == 'function')
			{
				tagBox.quickClicks();
			}
		}
		catch(err) {
		
		}
		if (a_mode == true)
		{	
			AlchemyAPI_ShowActiveTags();
			AlchemyAPI_DisplayTagSuggestions();
		}
	}
	

	function AlchemyAPI_AddSingleUserTag()
	{
		var thetags = AlchemyAPI_GetTagsVal();
		g_userClickedSuggest = false;

		var l_flag = false;
		if (g_AlchemyAPI_SuggestionsVisible == false)
		{
			AlchemyAPI_DisplayTags('{"tags":[]}');
			l_flag = true;
		}
		var l_tags = jQuery('#myOwnTags').val();
		jQuery('#myOwnTags').val('');
		
	
		var l_tagArr = l_tags.split(',');
		
		for (var i = 0;i < l_tagArr.length;++i)
		{
			l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);
			AlchemyAPI_AddSingleTag(l_tagArr[i], false);	
		}

		AlchemyAPI_ShowActiveTags();

		if (l_flag == true)
		{
			AlchemyAPI_TagPost(false);
		}
	}

	function AlchemyAPI_ShowActiveTags()
	{
		var l_tags = AlchemyAPI_GetTagsVal();

		var l_tagArr = l_tags.split(',');

		var l_html = "";
		
		if ( l_tagArr.length > 0 && l_tagArr[0].length > 0)
		{
			for (var i = 0;i < l_tagArr.length;++i)
			{
				if (l_html.length > 0) 
					l_html += " ";
	
				l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);
				l_html += "<div style='margin:5px; float:left;'><span><a id='" + i + "' onclick=\"AlchemyAPI_RemoveSingleTag('" + l_tagArr[i] + "'); tagBox.quickClicks(); return false;\">X</a>&nbsp;" + l_tagArr[i] + "</span></div>";

			}
		}

		jQuery('#tActiveTags1').html(l_html);

		if (l_tagArr.length > 0 && l_tagArr[0].length > 0)
		{
			//AlchemyAPI_SetupScrollableB();
			jQuery('#tActiveTagsTable').show();
			jQuery('#tActiveTagsHeading').show();
		}
	}

	function AlchemyAPI_SetTags()
	{
		if (g_tagSuggestions == null)
		{
			return;
		}

		var l_tags = AlchemyAPI_GetTagsVal();
		var l_tagArr = l_tags.split(',');

		for (var i = 0;i < l_tagArr.length;++i)
		{
			l_tagArr[i] = AlchemyAPI_TrimStr(l_tagArr[i]);
		}

		for (var i = 0;i < g_tagSuggestions.tags.length;++i)
		{
			var l_text = AlchemyAPI_TrimStr(g_tagSuggestions.tags[i].text);
			l_tagArr = AlchemyAPI_AddTag(l_tagArr, l_text);
		}

		var l_tagText = "";
		for (var i = 0;i < l_tagArr.length;++i)
		{
			if (l_tagText.length > 0) l_tagText += ",";
			l_tagText += l_tagArr[i];
		}

		AlchemyAPI_SetTagsVal(l_tagText);
		try {
			if (typeof tag_update_quickclicks == 'function')
			{
				tag_update_quickclicks();
			}
		}
		catch(err) {
		}

		AlchemyAPI_ShowActiveTags();
		AlchemyAPI_DisplayTagSuggestions();
	}

	function parseJSONObj(a_json)
	{
		try
		{
			var j = eval('(' + a_json + ')');
			return j;
		}
		catch(e)
		{
		}

		if (a_json == "Type some text first.")
		{
			if (g_userClickedSuggest == true)
			{
				var l_form = document.getElementById('post');
				var l_text = "" + l_form.content.value;
				if (l_text.length < 50)
				{
					jQuery('#alchemyapi_tags').html("Type some additional text to receive tag suggestions.");
				}
				else
				{
					jQuery('#alchemyapi_tags').html("No tag suggestions are available for this text.");
				}
			}
		}
		else if (a_json == "No tag suggestions at this time.")
		{
			AlchemyAPI_DisplayTags('{"tags":[]}');
		}
		else jQuery('#alchemyapi_tags').html(a_json);

		throw new SyntaxError("parseJSONObj");
	}
	
	var g_tagSuggestions = null;

	function AlchemyAPI_DisplayTags(a_tags)
	{
		g_AlchemyAPI_SuggestionsVisible = true;

		var l_txt = a_tags.split("\n");
		var l_tags = l_txt[0];

		var l_tObjs = null;

		try
		{
			l_tObjs = parseJSONObj(l_tags);
		}
		catch (e)
		{
			return;
		}

		
		g_tagSuggestions = l_tObjs;

		
		if (g_tagSuggestions.tags.length < 1)
		{

			if (g_userClickedSuggest == true)
			{
				var l_form = document.getElementById('post');
				var l_text = "" + l_form.content.value;
				if (l_text.length < 50)
				{
					jQuery('#alchemyapi_tags').html("Type some additional text to receive tag suggestions.");
				}
				else
				{
					jQuery('#alchemyapi_tags').html("No tag suggestions are available for this text.");
				}
			}
		}
		
		AlchemyAPI_DisplayTagSuggestions();
	}

	function AlchemyAPI_DisplayTagSuggestions()
	{
		var l_html = "<table><tr><td><table id='tTable1'><tbody style='overflow-y: scroll; overflow-x: hidden; height: 150px; width: 400px;'>";
	
		if (g_tagSuggestions != null)
		{
		var l_total = 0;
		var display_limit= 10;
		for (var i = 0;i < g_tagSuggestions.tags.length;++i)
		{
				if( display_limit > 0 ) {
					var l_text = AlchemyAPI_TrimStr(g_tagSuggestions.tags[i].text);
                    l_text = l_text.replace(',','');
					var l_origText = l_text;
					if (l_text.length > 40)
					{
						l_text = l_text.substr(0, 40) + "...";
					}
					if (AlchemyAPI_IsTagActive(AlchemyAPI_TrimStr(g_tagSuggestions.tags[i].text)) == false)
					{
						display_limit--;
						l_html += "<tr><td>" + l_text + "</td><td style='padding-right:30px; width:80px;'> [&nbsp;<a href='#' onclick=\"AlchemyAPI_AddSingleTag('" + l_origText + "', true); return false;\">Use&nbsp;Tag</a>&nbsp;] </td></tr>";

						++l_total;
					}
				}

		}
		}

		l_html += "</tbody></table></td></tr></table>";
        
		jQuery('#alchemyapi_tags').html(l_html);
		if (l_total > 0)
		{
			jQuery('#alchemyapi_tags_label').show();
		}
		else
		{
			jQuery('#alchemyapi_tags_label').hide();
		}
		if (l_total > 1)
		{
			jQuery('#alchemyapi_tags_add').show();
		}
		else
		{
			jQuery('#alchemyapi_tags_add').hide();
		}
	}

	
	function AlchemyAPI_disableEnterKey(e)
	{
     		var key;     
	     
		if(window.event)
			key = window.event.keyCode; //IE
		else
			key = e.which; //firefox     

		if (key == 13)
		{
			AlchemyAPI_AddSingleUserTag();
		}

		return (key != 13);
	}

	//]]>
	</script>

	<?php if (!function_exists('add_meta_box')): ?>
	<fieldset id="alchemyapi_dbx" class="dbx-box">
	<div class="dbx-handle"><h3>AlchemyTagger</h3></div>
	<div class="dbx-content">
	<?php endif; ?>
	
	<table style="width:100%;">
	<tr><td style="width:50%; padding-top:10px; vertical-align:top;">
		<input type="button" class="button" onclick="AlchemyAPI_TagPost(true)" value="Suggest Tags" /><br /><br />
	</td>
	<td style="text-align:right; vertical-align:top; width:50%;">
		<table>
		<tr><td>
				<table style="width:100%;">
				<tr><td>
					Or, enter your own tags:&nbsp;<br/><input onkeypress="return AlchemyAPI_disableEnterKey(event);" type="text" value="" id="myOwnTags" maxlength="25"/>
				</td><td>
					<input type="button" class="button" onclick="AlchemyAPI_AddSingleUserTag(); return false;" value="Add" />
				</td></tr></table>
		</td></tr>
		<tr><td>
			<div style="width:100%; text-align:right;"><i>Separate tags with commas</i></div>
		</td></tr>
		</table>
	</td></tr>
	<tr><td style="vertical-align:top;">
		<span style='font-size: 10pt; font-weight: bold; display: none' id="alchemyapi_tags_label"><font size="3">Tag Suggestions</font></span>
	
		<div id="alchemyapi_tags" style='font-size: 10pt; margin-bottom: 10px; padding-right:50px;'>
		</div>

		<input type="button" class="button" id="alchemyapi_tags_add" 
			onclick="AlchemyAPI_SetTags()" value="Use all Suggested Tags" style="display: none" />
	</td>
	<td style="vertical-align:top;">
		<div id="tActiveTagsHeading" style="display:none; padding-left:50px;"><b><font size='3'>Active Tags</font></b></div>
		<table id="tActiveTagsTable" style="display:none;">
        <tbody style="overflow-y: scroll; overflow-x: hidden; height: 150px; width: 350px;">
		<tr><td>
			<input type="text" style='display:none;' name='tags-input' id="tags-input"></input>
			<div id='tActiveTags1' style='vertical-align:top; padding-top:0px; padding-left:50px; '></div>
		</td></tr>
		<tr><td>&nbsp;</td></tr>
        </tbody>
		</table>
	</td></tr></table>

	<?php if (!function_exists('add_meta_box')): ?>
	</div>
	</fieldset>
	<?php endif; ?>
        <?php 
}

?>
