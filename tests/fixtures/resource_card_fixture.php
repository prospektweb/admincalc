<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/lib/Documents/PdoConnection.php';
/** Isolated fixture; never connects to Bitrix or a site database. */
function resourceCardFixture(int $version): array
{
    $pdo = new PDO('sqlite::memory:'); $db = new \Prospektweb\Calc\Documents\PdoConnection($pdo);
    foreach ([
        'b_option (MODULE_ID TEXT,NAME TEXT,VALUE TEXT,SITE_ID TEXT)',
        'b_iblock (ID INTEGER PRIMARY KEY,CODE TEXT,IBLOCK_TYPE_ID TEXT,VERSION INTEGER,ACTIVE TEXT)',
        'b_iblock_property (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,CODE TEXT,ACTIVE TEXT,PROPERTY_TYPE TEXT,USER_TYPE TEXT,MULTIPLE TEXT,WITH_DESCRIPTION TEXT,LINK_IBLOCK_ID INTEGER)',
        'b_iblock_element (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,IBLOCK_SECTION_ID INTEGER,NAME TEXT,CODE TEXT,ACTIVE TEXT,SORT INTEGER,PREVIEW_TEXT TEXT,PREVIEW_TEXT_TYPE TEXT,DETAIL_TEXT TEXT,DETAIL_TEXT_TYPE TEXT,TIMESTAMP_X TEXT,XML_ID TEXT)',
        'b_iblock_section (ID INTEGER PRIMARY KEY,IBLOCK_ID INTEGER,IBLOCK_SECTION_ID INTEGER,NAME TEXT)',
        'b_iblock_element_property (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT,DESCRIPTION TEXT)',
        'b_catalog_iblock (IBLOCK_ID INTEGER PRIMARY KEY,PRODUCT_IBLOCK_ID INTEGER,SKU_PROPERTY_ID INTEGER)',
        'b_catalog_product (ID INTEGER PRIMARY KEY,VAT_ID INTEGER,VAT_INCLUDED TEXT,PURCHASING_PRICE TEXT,PURCHASING_CURRENCY TEXT,WEIGHT TEXT,LENGTH TEXT,WIDTH TEXT,HEIGHT TEXT,QUANTITY TEXT,TYPE INTEGER,TIMESTAMP_X TEXT)',
        'b_catalog_price (ID INTEGER PRIMARY KEY,PRODUCT_ID INTEGER,CATALOG_GROUP_ID INTEGER,PRICE TEXT,CURRENCY TEXT,QUANTITY_FROM INTEGER,QUANTITY_TO INTEGER,TIMESTAMP_X TEXT)',
        'b_catalog_group (ID INTEGER PRIMARY KEY,NAME TEXT,BASE TEXT)',
        'b_catalog_vat (ID INTEGER PRIMARY KEY,NAME TEXT,RATE TEXT,ACTIVE TEXT)',
        'b_catalog_currency (CURRENCY TEXT PRIMARY KEY,BASE TEXT,AMOUNT TEXT)',
    ] as $definition) $db->execute('CREATE TABLE ' . $definition);
    $db->execute("INSERT INTO b_option VALUES ('prospektweb.calc','document_resource_provider','bitrix:test',NULL)");
    $codes = ['CALC_MATERIALS'=>41,'CALC_MATERIALS_VARIANTS'=>42,'CALC_OPERATIONS'=>43,'CALC_OPERATIONS_VARIANTS'=>44,'CALC_EQUIPMENT'=>45,'CALC_SUPPLIERS'=>46];
    foreach ($codes as $code=>$iblock) {
        $db->execute("INSERT INTO b_option VALUES ('prospektweb.calc',?,?,NULL)",['iblock_'.strtolower($code),(string)$iblock]);
        $db->execute("INSERT INTO b_iblock VALUES (?,?,'calculator_catalog',?,'Y')",[$iblock,$code,$version]);
        $properties = [];
        if ($iblock !== 46) $properties += ['PARAMETRS'=>['S','Y','Y',0], 'SOURCE_LINKS'=>['S','Y','Y',0], 'UNOWNED'=>['S','N','N',0]];
        if (in_array($iblock,[41,42],true)) $properties['SUPPLIERS']=['E','Y','N',46];
        if (in_array($iblock,[42,44],true)) $properties['CML2_LINK']=['E','N','N',$iblock-1];
        if ($iblock===46) $properties['ENTITY_KEY']=['S','N','N',0];
        $columns=['IBLOCK_ELEMENT_ID INTEGER PRIMARY KEY']; $index=1;
        foreach ($properties as $property=>$p) {
            $pid=$iblock*100+$index++;
            $db->execute("INSERT INTO b_iblock_property VALUES (?,?,?,'Y',?,?,?, ?, ?)",[$pid,$iblock,$property,$p[0],$property==='CML2_LINK'?'SKU':'',$p[1],$p[2],$p[3]]);
            // Multiple columns are intentionally invalid serialized caches.
            $columns[]='PROPERTY_'.$pid.' TEXT';
            if ($p[1]==='N' && $p[2]==='Y') $columns[]='DESCRIPTION_'.$pid.' TEXT';
        }
        $db->execute('CREATE TABLE b_iblock_element_prop_s'.$iblock.' ('.implode(',',$columns).')');
        $db->execute('CREATE TABLE b_iblock_element_prop_m'.$iblock.' (ID INTEGER PRIMARY KEY,IBLOCK_ELEMENT_ID INTEGER,IBLOCK_PROPERTY_ID INTEGER,VALUE TEXT,DESCRIPTION TEXT)');
    }
    $db->execute('INSERT INTO b_catalog_iblock VALUES (42,41,4205),(44,43,4404),(45,0,0)');
    $db->execute("INSERT INTO b_iblock_section VALUES (91,41,NULL,'Материалы'),(92,41,91,'Бумага'),(93,43,NULL,'Печать')");
    $elements=[[100,41,92,'Бумага','Y',500],[101,42,null,'Бумага 300','Y',100],[102,42,null,'Бумага 250','N',200],
        [200,43,93,'Печать','Y',500],[201,44,null,'Печать 4+0','Y',100],[202,44,null,'Печать 4+4','Y',200],
        [300,45,null,'Машина','Y',500],[400,46,null,'Поставщик','Y',500],[401,46,null,'Неактивный поставщик','N',501],
        [999,42,null,'Другой вариант','Y',500]];
    $rowId=1;
    foreach ($elements as [$id,$iblock,$section,$name,$active,$sort]) {
        $db->execute("INSERT INTO b_iblock_element VALUES (?,?,?,?,?,?,?,?,'html',?,'html','2026-09-09 00:00:00',?)",[$id,$iblock,$section,$name,'code-'.$id,$active,$sort,' <p>Описание &amp;</p> ','<p>Подробно &amp;</p>','protected-'.$id]);
        $properties=$db->rows('SELECT * FROM b_iblock_property WHERE IBLOCK_ID=? ORDER BY ID',[$iblock]);
        $db->execute('INSERT INTO b_iblock_element_prop_s'.$iblock.' (IBLOCK_ELEMENT_ID) VALUES (?)',[$id]);
        foreach ($properties as $p) {
            $values=[];
            if ($p['CODE']==='PARAMETRS') $values=[['rate','12.50|Цена &amp;|Описание'],['speed','0|Скорость|']];
            if ($p['CODE']==='SOURCE_LINKS') $values=[['https://example.test/'.$id,'Источник|Точно &amp;']];
            if ($p['CODE']==='UNOWNED') $values=[['literal unknown '.$id,'']];
            if ($p['CODE']==='SUPPLIERS') $values=[['400',''],['401','']];
            if ($p['CODE']==='ENTITY_KEY') $values=[['supplier-'.$id,'']];
            if ($p['CODE']==='CML2_LINK') $values=[[$id===999?'888':($iblock===42?'100':'200'),'']];
            if ($version===2 && $p['MULTIPLE']==='N') {
                $db->execute('UPDATE b_iblock_element_prop_s'.$iblock.' SET PROPERTY_'.$p['ID'].'=? WHERE IBLOCK_ELEMENT_ID=?',[$values[0][0]??null,$id]);
            } else foreach ($values as [$value,$description]) $db->execute('INSERT INTO '.($version===2?'b_iblock_element_prop_m'.$iblock:'b_iblock_element_property').' VALUES (?,?,?,?,?)',[$rowId++,$id,(int)$p['ID'],$value,$description]);
            if ($p['MULTIPLE']==='Y') $db->execute('UPDATE b_iblock_element_prop_s'.$iblock.' SET PROPERTY_'.$p['ID'].'=? WHERE IBLOCK_ELEMENT_ID=?',['not-a-serialized-value',$id]);
        }
        if ($iblock===46) continue;
        $db->execute("INSERT INTO b_catalog_product VALUES (?,1,'N','12.50','RUB','25.00','297.00','210.00',NULL,'37',?,'2026-09-09 00:00:00')",[$id,in_array($id,[100,200],true)?3:($id===300?1:4)]);
        $db->execute("INSERT INTO b_catalog_price VALUES (?,?,1,'18.75','RUB',NULL,NULL,'2026-09-09 00:00:00')",[$id*10,$id]);
        $db->execute("INSERT INTO b_catalog_price VALUES (?,?,2,'16.75','RUB',NULL,NULL,'2026-09-09 00:00:00')",[$id*10+1,$id]);
    }
    $db->execute("INSERT INTO b_catalog_group VALUES (1,'Базовая','Y'),(2,'Дилер','N')");
    $db->execute("INSERT INTO b_catalog_vat VALUES (1,'Без НДС','0','Y'),(2,'НДС','20','Y'),(3,'Старая','18','N')");
    $db->execute("INSERT INTO b_catalog_currency VALUES ('RUB','Y','1'),('PRC','N','1'),('MRG','N','1')");
    return [$pdo,$db];
}
