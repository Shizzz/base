<?php
session_start();
require dirname(__FILE__)."/../vendor/autoload.php";
require "Database.class.php";
require "Library.class.php";
require "FB2.extend.php";

setlocale(LC_MONETARY, 'ru_RU');

if (!isset($_SESSION['Tables'])) {
    $_SESSION['Tables'] = Database::GetTableInfo();
    $_SESSION['Attrs'] = Database::GetAttrInfo();
}

if (!isset($_SESSION['elements'])) {
    $_SESSION['elements'] = [];
}

if (!isset($_SESSION['types'])) {
    $_SESSION['types'] = Database::TableQuery('SELECT `id`,`type`,`SQLData` FROM `types`',null);
}

if (!isset($_SESSION['catalog'])) {
    $_SESSION['catalog'] = Database::TableQuery('SELECT `id`,`name`,`table` FROM `catalog`',null,true);
}

class system
{
    public static function ReturnUrlEncode($string)
    {    
		  return str_replace("&","amp;",$string);
    }
    
    public static function Log($message,$separator = false,$datetime = false,$echo = false) {
        if ($datetime) { 
            $date = new DateTime();
            $message = $message." ".$date->format('d-m-Y H:i:s');
        }
        if ($separator) {
            $len = strlen($message);
            $r = (100 - strlen($message))/2;
            $message = str_repeat("-",$r).$message.str_repeat("-",$r);
            if (($r*2+$len)<100) {
                $message = "$message-";
            }
        }
        if ($echo) {
            echo $message,"\r\n";
        }

        $log_filename = Config::read('log.file');
        if (!file_exists($log_filename)) 
        {
            // create directory/folder uploads.
            mkdir($log_filename, 0777, true);
        }
        $log_file_data = $log_filename.'/log_' . date('d-m-Y') . '.log';
        file_put_contents($log_file_data, $message . "\n", FILE_APPEND);
    }

    public static function ReturnUrlDecode($string)
    {    
		  return str_replace("amp;","&",$string);
    }

    public static function &ElementGetByUid($uid){
        return $_SESSION['uids'][$uid];
    }

    public static function RecreateSystemInfo() {
        $_SESSION['Tables'] = Database::GetTableInfo();
        $_SESSION['Attrs'] = Database::GetAttrInfo();
        $_SESSION['types'] = Database::TableQuery('SELECT `id`,`type`,`SQLData` FROM `types`',null);
        $_SESSION['catalog'] = Database::TableQuery('SELECT `id`,`name`,`table` FROM `catalog`',null,true);

        if (isset($_SESSION['elements'])) {
            foreach ($_SESSION['elements'] as $pageKey => &$page) {
                foreach ($page as $key => &$el) {
                    if ($el['type']=='table') {
                        $el['tableInfo'] = system::GetTableInfo($el['table']);
                        $el['$attrInfo'] = system::GetAttrInfo($el['table']);
                    }
                }
            }
        }
    }

    public static function &GetSetElement($page,$type,$table,$id=null,$parent=null) {
        $element = null;
        if (!isset($_SESSION['elements'][$page])) {
            $_SESSION['elements'][$page] = [];
        }
        foreach ($_SESSION['elements'][$page] as &$elem){
            if ($elem['type'] == $type && $elem['table'] == $table && $elem['parent'] == $parent && $elem['id'] == $id) {
                $element = &$elem;
            }
        }
        if ($element == null) {
            $uid = uniqid();
            $_SESSION['elements'][$page][$uid] = [];
            $_SESSION['elements'][$page][$uid]['uid'] = $uid;
            $_SESSION['elements'][$page][$uid]['id'] = $id;
            $_SESSION['elements'][$page][$uid]['type'] = $type;
            $_SESSION['elements'][$page][$uid]['table'] = $table;
            $_SESSION['elements'][$page][$uid]['parent'] = $parent;

            $element = &$_SESSION['elements'][$page][$uid];
            $_SESSION['uids'][$uid] = &$element;
        }

        return $element;
    }

    public static function UpdateFormAttrInfo(&$attrInfo) {
        foreach ($attrInfo as $row) {
            $first = $row;
            break;
        }
        $tableID = $first['table_id'];

        foreach ($_SESSION['catalog'] as $catalog) {
            if ($catalog['table'] == $tableID) {
                $NewInfo['clear_name'] = $catalog['name'];
                $NewInfo['name'] = $catalog['name'];
                $NewInfo['id'] = $catalog['id'];
                $NewInfo['type'] = 7;
                $NewInfo['colspan'] = 1;
                $NewInfo['reference'] = null;

                array_push($attrInfo, $NewInfo);
            }
        }
    }

    public static function GetAttrInfo($id){
        $data = [];

        foreach ($_SESSION['Attrs'] as $row) {
            if ($row['table_id'] == $id) {
                $data[] = $row;
            }
        } 

        return $data;
    }

    public static function GetTableInfo($id){
        foreach ($_SESSION['Tables'] as $row) {
            if ($row['id'] == $id) {
                return $row;
            }
        } 

        return null;
    }

    public static function GetTableID($Name){
        foreach ($_SESSION['Tables'] as $row) {
            if ($row['name'] == $Name) {
                return $row['id'];
            }
        } 

        return null;
    }

    public static function GetAttrByName($Name,$TableID){
        $Attrs = system::GetAttrInfo($TableID);

        foreach ($Attrs as $row) {
            if ($row['name'] == $Name) {
                return $row;
            }
        } 

        return null;
    }

    public static function CheckCatalogElement($CatalogID,$ElementName) {
        $SelectDB = new Database();
        $SelectDB->Table = 'catalog_element';
        $SelectDB->SelectValues = array('id');
        $SelectDB->WhereValues = array('catalog_id'=>$CatalogID, 'name'=>$ElementName);
        $SelectDB->PrepareSelect();
        $Data = $SelectDB->ExecSelect();

        if (count($Data)==0) {
            $Insert = new Database();
            $Insert->Table = 'catalog_element';
            $Insert->Values = array('catalog_id'=>$CatalogID,'name'=>$ElementName, 'visible'=>1, 'enabled'=>1);
            $Insert->PrepareInsert();
            $NewData = $Insert->ExecInsert();
            return $NewData;
        } else {
            return $Data[0]['id'];
        }
    }

    public static function LinkCatalogElement($ElementID,$ObjectID,$Attr) {
        $Select = new Database();
        $Select->Table = 'element_link';
        $Select->SelectValues = array($Attr);
        $Select->WhereValues = array('element'=>$ElementID, $Attr=>$ObjectID);
        $Select->PrepareSelect();
        $Data = $Select->ExecSelect();

        if (count($Data)==0) {
            $Insert = new Database();
            $Insert->Table = 'element_link';
            $Insert->Values = array('element'=>$ElementID, $Attr=>$ObjectID);
            $Insert->PrepareInsert();
            $Insert->ExecInsert();
        }
    }

    public static function GetFormData($table,$attrs,$formID){
        $query = 'SELECT ';
        $id = null;
        $catalogExists = false;

        foreach ($attrs as $attr) {
            if ($attr['type'] == 3) {
                $query = $query."CASE WHEN T0.`".$attr['name']."` = 1 THEN 'true' ELSE 'false' END";
            } else {
                $query = $query.'T0.`'.$attr['name'].'`';
            }
            $query = $query.' AS `'.$attr['clear_name'].'`, ';
        }

        $query = $query.'T0.`id` FROM `'.$table['name'].'` T0';

        $query = $query.' WHERE T0.`id` = :param';

        $data = Database::TableQuery($query,$formID);
        return $data;
    }

    public static function GetTableData ($table,$attrs,$master_id=null,$master_link=null,$formID=null){
        $query = 'SELECT ';
        $id = null;
        $catalogExists = false;

        $i = 3;
        $join = "";
        $group = " GROUP BY ";

        foreach ($attrs as $attr) {
            switch ($attr['type']) {
                case 3:
                    $query = $query."CASE WHEN T0.`".$attr['name']."` = 1 THEN 'Истина' ELSE 'Ложь' END";
                    $query = $query.' AS `'.$attr['clear_name'].'`, ';

                    $group = $group.'T0.`'.$attr['name'].'`,';
                    break;
                case 4:
                    if ($master_link == $attr['id']) {
                        break;
                    }
                    if ($attr['reference'] != null && $attr['reference'] != 0) {
                        foreach ($_SESSION['Attrs'] as $row) {
                            if ($row['table_id'] == $attr['reference'] && $row['primary'] == 1) {
                                $ref = $row;
                            }
                        }
                        $query = $query.'T'.$i.'.`'.$ref['name'].'`';
                        $query = $query.' AS `'.$attr['clear_name'].'`, ';

                        $group = $group.'T'.$i.'.`'.$ref['name'].'`,';
                        $join = $join." LEFT JOIN `".$_SESSION['Tables'][$attr['reference']]['name']."` T".$i." ON T0.`".$attr["name"]."` = T".$i.".`id` ";
                        $i++;
                    } else {
                        $query = $query.'T0.`'.$attr['name'].'`';
                        $query = $query.' AS `'.$attr['clear_name'].'`, ';

                        $group = $group.'T0.`'.$attr['name'].'`,';
                    }
                    break;
                default:
                    $query = $query.'T0.`'.$attr['name'].'`';
                    $query = $query.' AS `'.$attr['clear_name'].'`, ';

                    $group = $group.'T0.`'.$attr['name'].'`,';
                    break;
            }
        }
        $group = $group." T0.`id`";

        foreach ($_SESSION['catalog'] as $catalog) {
            if ($catalog['table'] == $table['id']) {
                $catalogExists = true;
                $query = $query."GROUP_CONCAT(CASE WHEN T2.`catalog_id` = ".$catalog['id']." THEN T2.`name` END) as '".$catalog['name']."',";
            }
        }

        if ($catalogExists) {
            foreach ($_SESSION['Attrs'] as $a) {
                if ($a['table_id']==7 && $a['reference']==$table['id']) {
                    $catalog_link = $a['name'];
                }
            }
        }

        $query = $query.'T0.`id` FROM `'.$table['name'].'` T0';

        if ($catalogExists) {
            $query = $query." LEFT JOIN `element_link` T1 on T1.`".$catalog_link."` = T0.`id`";
            $query = $query." LEFT JOIN `catalog_element` T2 on T2.`id` = T1.`element`";
        }

        $query = $query.$join;

        if ($master_link !== null) {
            $query = $query.' WHERE T0.`'.$_SESSION['Attrs'][$master_link]['name'].'` = :param';
            $id = $master_id;
        }

        if ($formID !== null) {
            $query = $query.' WHERE T0.`id` = :param';
            $id = $formID;
        }

        if ($catalogExists) {
            $query = $query.$group;
            $query = $query." ORDER BY T0.`id`";
        }

        $data = Database::TableQuery($query,$id);
        return $data;
    }

    public static function GetParam($table,$id){
        $catalog_link = null;

        foreach ($_SESSION['Attrs'] as $a) {
            if ($a['table_id']==7 && $a['reference']==$table['id']) {
                $catalog_link = $a['name'];
            }
        }
        return Database::GetParam($catalog_link,$id);
    }

    public static function GetCert($id, $type, $part) {
        if ($type=='ca') {
            $Cert = Database::GetCert($id);
            $CertName = $Cert['Name'];
            $ServerID = $Cert['Server'];
    
            $Server = Database::GetServerInfo($ServerID);
    
            $ServerName = $Server['Name'];
            $dir = dirname(__FILE__)."/../";
            $file = file_get_contents($dir."easyrsa/$ServerName/EasyRSA/pki/ca.crt", true);
    
            if ($part) {
                $pos1 = strpos($file, '-----BEGIN CERTIFICATE-----');
                $pos2 = strpos($file, '-----END CERTIFICATE-----');
    
                $string = substr($file, $pos1, $pos2-$pos1+25);
            } else {
                $string = $file;
            }
        }

        if ($type=='cert') {
            $Cert = Database::GetCert($id);
            $CertName = $Cert['Name'];
            $ServerID = $Cert['Server'];
    
            $Server = Database::GetServerInfo($ServerID);
    
            $ServerName = $Server['Name'];
            $dir = dirname(__FILE__)."/../";
            $file = file_get_contents($dir."easyrsa/$ServerName/EasyRSA/pki/issued/$CertName.crt", true);

            if ($part) {
                $pos1 = strpos($file, '-----BEGIN CERTIFICATE-----');
                $pos2 = strpos($file, '-----END CERTIFICATE-----');
    
                $string = substr($file, $pos1, $pos2-$pos1+25);
            } else {
                $string = $file;
            }
        }

        if ($type=='key') {
            $Cert = Database::GetCert($id);
            $CertName = $Cert['Name'];
            $ServerID = $Cert['Server'];
    
            $Server = Database::GetServerInfo($ServerID);
    
            $ServerName = $Server['Name'];
            $dir = dirname(__FILE__)."/../";
            $file = file_get_contents($dir."easyrsa/$ServerName/EasyRSA/pki/private/$CertName.key", true);
    
            if ($part) {
                $pos1 = strpos($file, '-----BEGIN PRIVATE KEY-----');
                $pos2 = strpos($file, '-----END PRIVATE KEY-----');
    
                $string = substr($file, $pos1, $pos2-$pos1+25);
            } else {
                $string = $file;
            }
        }

        return $string;
    }

    public static function GetConfig($id,$html) {
        $CA = system::GetCert($id,'ca',True);
        $KEY = system::GetCert($id,'key',True);
        $CERT = system::GetCert($id,'cert',True);
        
        $Cert = Database::GetCert($id);
        $CertName = $Cert['Name'];
        $ServerID = $Cert['Server'];
        $ServerName = $Server['Name'];

        $Configurations = Database::GetConfigs($ServerID);
        $Config = "";

        foreach ($Configurations as $conf) {
            $Config = $Config.$conf['Conf']."\n";
        }

        if (!$html) {
            $Config = str_replace('</p>',"\n",$Config);
            $Config = str_replace('<p>',"",$Config);
            $Config = str_replace('<br>',"\n",$Config);
            $Config = str_replace('<pre>',"",$Config);
            $Config = str_replace('</pre>',"\n",$Config);
            $Config = str_replace('&lt;',"<",$Config);
            $Config = str_replace('&gt;',">",$Config);
            //$Config = strip_tags($Config);
        }

        $file = "$Config\n\n<ca>\n$CA\n</ca>\n<cert>\n$CERT\n</cert>\n<key>\n$KEY\n</key>";

        return $file;
    }

    public static function UpdateVPNQuickStat ($data,$id) {
        $Name = $data[1];

        $oldData = Database::GetVPNData($Name,$id);

        $date = date('Y-m-d H:i:s');
        $timeFirst  = strtotime($oldData['Date']);
        $timeSecond = strtotime($date);
        $DateDiff = $timeSecond - $timeFirst;
        $RSpeed = ($data[3]-$oldData['BytesReceived'])/($DateDiff*1000);
        $SSpeed = ($data[4]-$oldData['BytesSent'])/($DateDiff*1000);

        $s = Database::CheckVPNQuickData($Name,$id);

        if (Database::CheckVPNQuickData($Name,$id) == null) {
            Database::QuickDataInsert($Name,$data[2],$data[3],$RSpeed,$data[4],$SSpeed,$data[5],$id);
        } else {
            Database::QuickDataUpdate($Name,$data[2],$data[3],$RSpeed,$data[4],$SSpeed,$data[5],$id);
        }
    }

    public static function UpdateVPNStat ($id) {
        $params = Database::GetVPNParam($id);

        $telnet = new Telnet($params['management'],$params['managementPort'],10,"OpenVPN");
        $telnet->setPrompt("END");
        $data = $telnet->exec("status",true);
        preg_match_all('/(.*?),(.*?),(.*?),(.*?),(.*?)\n/m', $data, $matches, PREG_SET_ORDER);
        $len = count($matches);
        $Names = "";
        for ($i = 1; $i < $len; ++$i)
        {
            
            $Names = $Names.$data[1].",";
            System::UpdateVPNQuickStat($matches[$i],$id);
            Database::VPNDataInsert($matches[$i][1],$matches[$i][2],$matches[$i][3],$matches[$i][4],$matches[$i][5],$id);
        }
        $Names = rtrim($Names,",");
        Database::ClearQuickData($Names,$id);
    }

    public static function GetRefTables($table){
        $References = [];
        
        $i = 0;
        foreach ($_SESSION['Attrs'] as $a) {
            if ($a['table_id'] !=7 && $a['reference']==$table['id']) {
                $References[$i] = $_SESSION['Tables'][$a['table_id']];
                $i++;
            }
        }
        return $References;
    }

    public static function generateCode($length=6) {
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHI JKLMNOPRQSTUVWXYZ0123456789";
        $code = "";
        $clen = strlen($chars) - 1;
        while (strlen($code) < $length) {
                $code .= $chars[mt_rand(0,$clen)];
        }
        return $code;
    }

    public static function CheckAuthUser() {
        if(!isset($_SESSION['authenticated_user'])) {
            return system::CheckUserHash();
        }
        return $_SESSION['authenticated_user'];
    }

    public static function CheckUserHash() {
        if (!isset($_COOKIE["id"]) || !isset($_COOKIE["hash"])) {
            $_SESSION['authenticated_user'] = 0;
            return false;
        }
        
        $id = $_COOKIE["id"];
        $hash = $_COOKIE["hash"];

        $OldHash = Database::GetUserHash($id);

        if ($OldHash == $hash) {
            $_SESSION['authenticated_user'] = 1;

            system::SetNewUserHash($id);
            system::RecreateSystemInfo();

            return true;
        } else {
            $_SESSION['authenticated_user'] = 0;
            unset($_COOKIE["id"]);
            unset($_COOKIE["hash"]);
            return false;
        }
    }

    public static function CheckUser($user,$pass) {
        $hpass = md5(md5(trim($pass)));

        $UserID = Database::CheckUser($user,$hpass);
        $_SESSION['temp'] = $UserID;
        
        if ($UserID > 0) {
            setcookie("id", $UserID, time()+60*60*24*30);
            system::SetNewUserHash($UserID);
            $_SESSION['authenticated_user'] = 1;
            return true;
        } else {
            return false;
        }
    }

    public static function SetNewUserHash($id) {
        $hash = md5(system::generateCode(10));

        if (Database::UpdateUserHash($id,$hash)) {
            setcookie("hash", $hash, time()+60*60*24*30,null,null,null,true);
        }
    }

    public static function UserExit() {
        if (isset($_COOKIE["id"])) {
            $id = $_COOKIE["id"];
            Database::UpdateUserHash($id,'');
        }

        unset($_COOKIE["id"]);
        unset($_COOKIE["hash"]);
        unset($_SESSION['authenticated_user']);
        session_destroy();
    }
}
?>