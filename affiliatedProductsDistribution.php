<?php
	/*	In allen Artikeln soll das 'System' auswählbar sein (Atria, Spider etc.) durch ein Multiselect.
		Basierend auf dieser Auswahl werden nun alle Artikel mit dem selben System zur Zubehör-Liste hinzugefügt.
		Als Skript, um alle alten Artikel auf den neuen Stand zu bringen. 
        
        @Paul Bernitz
        @2018
    */
    
	// Prepare magento for script execution.
    ini_set("memory_limit","512M");
	date_default_timezone_set("Europe/Berlin");
	define('MAGENTO_ROOT', '/var/www/vhosts/rs213855.rs.hosteurope.de/dev3_new');
	$compilerConfig = MAGENTO_ROOT . '/includes/config.php';
	if(file_exists($compilerConfig)){ include $compilerConfig; }
	$mageFilename = MAGENTO_ROOT . '/app/Mage.php';
	require_once($mageFilename);
	Mage::init();
	Mage::app()->getStore()->setConfig('catalog/frontend/flat_catalog_product', 0);
	Mage::app()->getCacheInstance()->banUse('translate');
	
	// For better visual feedback.
	echo "<meta charset='utf-8'>
		<style>
			*{ font-family: consolas; }
			table{ border-collapse: collapse; }
			tr, td{ border: 1px solid lightgray; padding: 5px; }
			h1,h2,h3{ background: lightgray; }
			legend{ font-size: 1.5em; }
			fieldset{ margin-top: 20px; }
		</style>";
	
	// Show a short message that the script started running.
	echo "Script '".__FILE__."' running...<hr>";
		
	// Receive a collection of all products.
	$collection = Mage::getModel('catalog/product')
		->getCollection()
		->addAttributeToSelect('up_sell_product_grid_table')
		->load();	
		
	// Start execution-time measuring
	$start = microtime(true);
	
	// ------------------------------------------------------------------------------------------------------------------------------------------
	/* 	Iterate over each product and save its id to the array with all all affiliated products.
		Please note: Split between 'W' and 'WW', too. */ 
	$affiliatedProducts = array();
	$affiliatedPositions = array();
	foreach($collection as $_product){
		if($_product !== ""){		
			$product_id = $_product->getId(); // Fetch the id from product.
			 
			$product = Mage::getModel('catalog/product')->setStoreId(0)->load($product_id); // Load the 'real' product.
			$lampsystem = explode(',', $product->getLampensystem());	// Get possible lampsystems from this product.
			
			$sku_suffix = end(explode('-', $product->getSku()));// Check if white/warmwhite/none
			if($sku_suffix == 'W') $sku_suffix = 'w';			// Suffix 'w'
			else if($sku_suffix == 'WW') $sku_suffix = 'ww';	// Suffix 'ww'
			else $sku_suffix = '0';								// Suffix '0' (None)
			
			// Go through each lampsystem of the product and put it in a list with the corresponding product id's.
			foreach($lampsystem as $sys){	
				if(empty($sys)){
					continue; // Skip if no lampsystem.
				}
				
                // Create an array for every new system.
				if(!array_key_exists($sys, $affiliatedProducts)){
					$affiliatedProducts[$sys] = array(); 
					array_push($affiliatedProducts[$sys], array());
				}
				
				// Check if this product can be added as 'Zubehör'. zubehoer_berechtigt = 'Yes' ?
				if($product->getAttributeText('zubehoer_berechtigt') === 'Yes'){
		
					// Calculate position here based on cable length and ballast unit.
					$lichtstrom   = $product->getLichtstrom();				// Lichtstrom (Leuchtmittel)
					$cable_length = $product->getKabellaenge() * 10;		// Kabellänge (Kabel)
					$ballast_unit = $product->getAusgangsleistungVgeraet();	// Watt (Vorschaltgerät)
					
					$price = number_format($product->getPrice(), 0);	
					$position = $price;
					if(!empty($lichtstrom)){ $position = $lichtstrom; }
					else if(!empty($cable_length)){ $position = $cable_length; }
					else if(!empty($ballast_unit)){ $position = $ballast_unit; }
					
					$position = intval($position);
					$affiliatedPositions[$product_id] = $position;
					$affiliatedProducts[$sys][$sku_suffix][] = $product_id; // Append to list of affiliated products.
				}
			}
		}
	}
	
	// ------------------------------------------------------------------------------------------------------------------------------------------
	/* 	Now that all affiliated products are collected and saved locally for reference,	
		the product collection has to be checked again to insert the specified affiliated products. */
	foreach($collection as $_product){
		if($_product !== ""){	
		
			$product_id = $_product->getId(); // Fetch the id from product.
			
			$product = Mage::getModel('catalog/product')->setStoreId(0)->load($product_id); // Load the 'real' product.
			$lampsystem = explode(',', $product->getLampensystem());	// Get possible lampsystems from this product.
		
			$sku_suffix = end(explode('-', $product->getSku()));// Check if white/warmwhite/none
			if($sku_suffix == 'W') $sku_suffix = 'w';			// Suffix 'w'
			else if($sku_suffix == 'WW') $sku_suffix = 'ww';	// Suffix 'ww' 
			else $sku_suffix = '0';								// Suffix '0' (None)	

			$related_data = array();
			foreach($lampsystem as $sys){
                
				/* 1.This is how you access the related children: 
				  [System (595,592)] [Endung ('0', 'w', 'ww')] [Index (0,1,2)] */
				foreach($affiliatedProducts[$sys][$sku_suffix] as $index => $related_id){
					if($product->getEntityId() === $related_id){
						continue;				
					}					
					
					// Set position value based on attribute.
					$allCategories = Mage::getModel('catalog/category')->getCollection()->addAttributeToSelect('name');
					$categoryIds = $_product->getCategoryIds();
					$cats = null;
					$isCable = false;
					$isBallast = false;
					$isIlluminant = false; 
					$isLeuchte = false;
					foreach($allCategories as $cat) {
						$cats[$cat->getId()] = $cat->getName();
						if($cat->getName() == "Kabel" && in_array($cat->getId(), $categoryIds)){ $isCable = true; }
						else if($cat->getName() == "Vorschaltgeräte" && in_array($cat->getId(), $categoryIds)){ $isBallast = true; }
						if(strpos($cat->getName(), "Leuchtmittel") !== false  && in_array($cat->getId(), $categoryIds)){
							$isIlluminant = true;
						}
						if(strpos($cat->getName(), "Leuchten") !== false  && in_array($cat->getId(), $categoryIds)){
							$isLeuchte = true;
						}
					}

					if($isLeuchte === false){
						// Calculate local position if no 'Leuchte'. 
						$position = $affiliatedPositions[$related_id];		
						$related_data[$related_id] = array('position' => $position);  
					}else{
						$position = $affiliatedPositions[$related_id];
						."Ist Vorschaltgerät: $isBallast, Ist Leuchtmittel: $isIlluminant, Leuchte: $isLeuchte</p>";			
						$related_data[$related_id] = array('position' => $position);  
					}
				}
				
				// If NOT 'W' or 'WW', just add.
				if($sku_suffix !== '0'){ 

					foreach($affiliatedProducts[$sys]['0'] as $index => $related_id){
						// Skip self.
						if($product->getEntityId() === $related_id){ continue; }
						
						$position = $affiliatedPositions[$related_id];
						$related_data[$related_id] = array('position' => $position); 
					}
				}else{
					// If 'W' or 'WW', just add 
					foreach($affiliatedProducts[$sys]['w'] as $index => $related_id){

						// Do not add itself to its own affiliated products.
						if($product->getEntityId() === $related_id){ continue; }
						
						// Set position.
						$position = $affiliatedPositions[$related_id];
						$related_data[$related_id] = array('position' => $position); 
					}
					
					foreach($affiliatedProducts[$sys]['ww'] as $index => $related_id){
		
						// Do not add itself to its own affiliated products.
						if($product->getEntityId() === $related_id){ continue; }
					
						// Set position.
						$position = $affiliatedPositions[$related_id];
						$related_data[$related_id] = array('position' => $position); 
					}
				}
			} 
			
			// Only update if the related products have changed.
			$isChanged = false;
			$relatedProducts = $product->getRelatedProducts();
			if(!empty($relatedProducts)){
				foreach($relatedProducts as $prod){
					if($related_data[$prod->getId()] !== null){
						$isChanged = true; 
						break;
					}
				}	
                
                // Save to product if there are changes.
                Mage::getResourceModel('catalog/product_link')->saveProductLinks(
                    $product, $related_data, Mage_Catalog_Model_Product_Link::LINK_TYPE_RELATED
                );
			}else{
				// Save to product if there are changes.
				Mage::getResourceModel('catalog/product_link')->saveProductLinks(
					$product, $related_data, Mage_Catalog_Model_Product_Link::LINK_TYPE_RELATED
				);
			}
		}
	}
	
	// ------------------------------------------------------------------------------------------------------------------------------------------	
	
	// Stop execution time measuring.
	$time = round((microtime(true) - $start), 2);
	echo "<hr>Success! Script took <b>$time</b> seconds to execute!<hr>";
?>
