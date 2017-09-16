<?php
/********************************************************************************\
| MIT License                                                                    |
| Copyright (c) [2017] [JunNineKim@gmail.com]                                    |
|---------------------------------------------------------------------           |
| Permission is hereby granted, free of charge, to any person obtaining a copy   |
| of this software and associated documentation files (the "Software"), to deal  |
| in the Software without restriction, including without limitation the rights   |
| to use, copy, modify, merge, publish, distribute, sublicense, and/or sell      |
| copies of the Software, and to permit persons to whom the Software is          |
| furnished to do so, subject to the following conditions:                       |
|                                                                                |
| The above copyright notice and this permission notice shall be included in all |
| copies or substantial portions of the Software.                                |
|                                                                                |
| THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR     |
| IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,       |
| FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE    |
| AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER         |
| LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,  |
| OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE  |
| SOFTWARE.                                                                      |
\********************************************************************************/
?>
<?php
header("Content-Type: text/html");
class SynoRSSTOMOVIE {
    private $categories = array("Dummy");
    private $query_value = array("Dummy");
    private $qurl = "https://www.tomovie.net/bbs/search.php?sfl=wr_subjectP@7CP@7Cwr_content&sop=and&stx=%s";
    private $purl = "https://www.tomovie.net/bbs/board.php?bo_table=%s&wr_id=%d";
    private $debug = false;//false true
    ########################################################################################################################
    ## DB Information.
    ########################################################################################################################
    private $mysql_hostname = 'localhost';
    private $mysql_username = 'root';
    private $mysql_password = '**********'; //비밀번호를 변경하였다면 여기를 수정하세요
    private $mysql_database = 'torrent';
    private $stay_some_hr   = 0; //최초 db에 등록 후 $stay_some_hr 시간 경과 후 ALLOW 한다.
    private $return_one_row_min = 3; //Query 검색한 결과가 Table에 저장되어 있을 때 RSS파일로 등록하려고 할 경우 "비어있거나 올바르지 않다" 
	                                 //또는 "rss file is empty or invalid."란 메세지가 뜬다.
                                     //이를위해 기존 검색후 $return_one_row_min 정의한 값보다 작을 경우 첫번째 레코드의 경우 Dispaly되게 한다.
    private $DB_debug       = true;//false true
    public  $Chk_Exists_in_DB = true;//false true //query값이 기존 저장된 Table에 존재하는가를 check할지 하지 않을지 결정한다.
    public  $cnt_returned_record = 0;//Query값이 기존 Table에 몆개의 레코드가 있는지를 반환한다.
    public  $max_returned_datetime = '';//Query값을 기준으로 기존 Table에 가장 큰 시간레코드를 반환한다.
    public  $date_diff = '';//Query값을 기준으로 기존 Table에 가장 큰 시간레코드와 Query값의 현 시점의 시간차를 반환한다.    
    private function DebugLog($msg)
    {
        if ($this->debug==true) {
            $dt = date('Y/m/d H:i:s');
            $bt = debug_backtrace();
            $func = isset($bt[1]) ? $bt[1]['function'] : '__main__';
            $caller = array_shift($bt);
            $file = basename($caller['file']);
            $output = sprintf("[%s] <%s:%s:%d> %s\n", $dt, $file, $func, $caller['line'], print_r($msg, true));
            file_put_contents('/tmp/rss_tomovie.log',$output."\r\n\r\n",FILE_APPEND);
        }
    }
    public function db_logger($msg_title, $msg) {
        if ($this->DB_debug==true) {
            $query = "INSERT INTO wr_log(log, creation_date) VALUES (SUBSTR(CONCAT('$msg_title',' : ','$msg'),1,2000),now());";
            mysql_query($query);
        }
    }
    public function DB_Connect()
    {
        $mysql_hostname = $this->mysql_hostname;
        $mysql_username = $this->mysql_username;
        $mysql_password = $this->mysql_password;
        $mysql_database = $this->mysql_database;
        $connect = mysql_connect($mysql_hostname, $mysql_username, $mysql_password);
        if(!$connect){
            $connection_status = filter_var(   'false', FILTER_VALIDATE_BOOLEAN); // false
            $this->DebugLog("Connection Failed :".mysql_error());
            return $connection_status;
            die('MySQL Connection Failed.');
        }
        $db_selected = mysql_select_db($mysql_database, $connect);
        if (!$db_selected) {
            $connection_status = filter_var(   'false', FILTER_VALIDATE_BOOLEAN); // false
            $this->DebugLog("Can\'t use :".mysql_error());
            return $connection_status;
            die ('Can\'t use '. $mysql_database.' '. mysql_error());
        }
        //3년이 지난 Query 데이터는 DB에서 삭제
        $query = "delete from rss_torrent where DATEDIFF (CURDATE(),reg_date) > 3*365*24";//3Year*365Day*24hr
        mysql_query($query);
        //3일이 지난 Log 데이터는 DB에서 삭제
        $query = "delete from wr_log where DATEDIFF (CURDATE(),creation_date) > 3";
        mysql_query($query);
        //3일이 지난 Log 데이터는 DB에서 삭제
        $query = "delete from wr_log where DATEDIFF (CURDATE(),creation_date) > 3";
        mysql_query($query);
        //ACTION : DELAYED 처리 아래 2줄, 최초 db에 등록 후 $stay_some_hr 시간 경과 후 ALLOW 한다.
        //$query = "update rss_torrent set action='BLOCK' where action='PENDING' and date_add(reg_date, INTERVAL 3 HOUR) < now()";
        //$result = mysql_query($query);
        $query = "update rss_torrent set action='DELAYED' where action='PENDING' and date_add(reg_date, INTERVAL '$stay_some_hr' HOUR) < now()";
        $result = mysql_query($query);
        //Return connection status.
        $connection_status = filter_var(   'true', FILTER_VALIDATE_BOOLEAN); // true
        $this->DebugLog("Connection connection_status :".$connection_status);
        return $connection_status;
    }
    public function check_query_in_db($query) {
        $select_query = str_replace(" ","%",$query);
        //Percentage(%)는 제외
        //$strip_query = preg_replace("/[ #\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#()\[\]\{\}]/", "", $select_query);
        $strip_query = preg_replace("/[ #\&\+\-@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#()\[\]\{\}]/", "", $select_query);
        $this->db_logger('torrent site query',$select_query);
        $this->DebugLog("torrent site query: $query ");
        $this->db_logger('torrent site strip_query',$strip_query);
        $this->DebugLog("torrent site strip_query: $strip_query ");        
        $selected_query = "select count(*), max(chk_date) from rss_torrent where title like '%$strip_query%'";
        $result = mysql_query($selected_query);
        $row = mysql_fetch_array($result);
        $total_record = $row[0];
        $max_datetime = $row[1];
        $this->date_diff = (strtotime(date('Y-m-d H:i:s')) - strtotime($max_datetime)) / 60;// 분단위
        $this->cnt_returned_record = $total_record;
        $this->db_logger('Selected row conut',$total_record);
        $this->DebugLog("Selected row conut :".$total_record);
        $this->db_logger('cnt_returned_record',$this->cnt_returned_record);
        $this->DebugLog("cnt_returned_record :".$this->cnt_returned_record);
        $this->max_returned_datetime = $max_datetime;
        $this->db_logger('Selected max datetime',$max_datetime);
        $this->DebugLog("Selected max datetime :".$max_datetime." Sysdate:".date('Y-m-d H:i:s') );
        $this->DebugLog("date_diff :".$this->date_diff );
        $this->db_logger('max_returned_datetime',$this->max_returned_datetime);
        $this->DebugLog("max_returned_datetime :".$this->max_returned_datetime);
        return $total_record;
    }
    public function check_item_db($title, $download, $check_record_num) {
        //마그넷 해시를 추출한다.
        $download = $download;
        $hashPos = strpos($download,"btih:") + 5;
        $hash = substr($download, $hashPos, 40);
        //해당해시의 이전동작 여부를 확인한다.
        $query = "select action from rss_torrent where hash = '$hash'";
        $result = mysql_query($query);
        $row = mysql_fetch_array($result);
        $action = $row['action'];
        $this->db_logger('torrent site hash',$hash);
        $this->DebugLog("torrent site hash :".$hash);
        $this->db_logger('current db action status',$action);
        $this->DebugLog("current db action status :".$action);
        //*******************************
        //타이틀 확인.
        //*******************************
        $title = $title;
        $stripTitle = preg_replace("/[ #\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#()\[\]\{\}]/", "", $title);
		//신규 Torrent 이고 Delay시간 zero일 경우 다운 준비 처리. 
        if ( $this->stay_some_hr == 0 and empty($action) ) {
            $action = "PENDING";
            $query = "insert into rss_torrent (title, hash, action, chk_date,reg_date) values('$stripTitle', '$hash', 'PENDING', now(),now())";
            mysql_query($query);
        }        
        $this->db_logger('torrent site title',$title);
        $this->DebugLog("torrent site title: $title ");
        $this->db_logger('torrent site stripTitle',$stripTitle);
        $this->DebugLog("torrent site stripTitle: $stripTitle ");
        // 파이프는 OR를 의미함.
        if ( (($this->return_one_row_min - $this->date_diff)>0 and $check_record_num==1 ) ) {
            $action = 'ALLOW';
            $query = "update rss_torrent set action = '$action', chk_date = now() where hash = '$hash'";
            mysql_query($query);
        }
        elseif ( $action == 'BLOCK'|| $action == 'PENDING' ) {
            $action = $action;
            $query = "update rss_torrent set action = '$action', chk_date = now() where hash = '$hash'";
            mysql_query($query);
        }
        //$action == 'ALLOW' 는 한번 다운 된 것이기 때문에 제외해야 된다. 반복 다운 안되게 해야 됨.
        elseif( $action == 'ALLOW' ){
            $action = "BLOCK";
            $query = "update rss_torrent set action = '$action', chk_date = now() where hash = '$hash'";
            mysql_query($query);
        }
        //ACTION : DELAYED 처리, 최초 db에 등록 후 $stay_some_hr 시간 경과 후 ALLOW 한다.
        elseif( $action == 'DELAYED' ){
            $action = "ALLOW";
            $query = "update rss_torrent set action = '$action', chk_date = now() where title = '$stripTitle'";
            mysql_query($query);
            $query = "update rss_torrent set action = '$action', chk_date = now() where hash = '$hash'";
            mysql_query($query);
        } else {
            //최초등록 판단 기준 : 기존 Table에 Query가 없는 경우 첫번째 레코드는 Arrowed로 처리한다.
            //DB에 HASH가 없는 것은 최초의 확인 된 것
            if ($this->cnt_returned_record == 0) {
                //토렌트 정보가 확인이 안될경우 ALLOW 처리
                $action = "ALLOW";
                $query = "insert into rss_torrent (title, hash, action, chk_date,reg_date) values('$stripTitle', '$hash', '$action', now(),now())";
                mysql_query($query);
            } else {
                //토렌트 정보가 확인이 안될경우 PENDING처리
                $action = "PENDING";
                $query = "insert into rss_torrent (title, hash, action, chk_date,reg_date) values('$stripTitle', '$hash', 'PENDING', now(),now())";
                mysql_query($query);
          }
        }
        return $action;
    }
    public function __construct()
    {
    }
    public function prepare($curl, $query)
    {
        //create curl-handles
        $this->DebugLog("curl:".$curl);
        $this->DebugLog("query:".$query);
        $this->query_value = $query;
        $mh = curl_multi_init();
        $categories = $this->categories;
        for($i = 0; $i < sizeof($categories); ++$i){
            $this->DebugLog("categories:".$i.".".$categories[$i]);
            //$url[] = sprintf($this->qurl, $categories[$i], urlencode($query));
            $url[] = STR_REPLACE("P@7CP@7C","%7C%7C",sprintf($this->qurl, urlencode($query)));//%7C%7C
            $this->DebugLog("url:".$i.".".$url[$i]);
            $ch[] = curl_init();
            // set URL and other appropriate options
            curl_setopt($ch[$i], CURLOPT_URL, $url[$i]);
            curl_setopt($ch[$i], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch[$i], CURLOPT_RETURNTRANSFER , true);
            curl_setopt($ch[$i], CURLOPT_HEADER , false);
            // and attach the handles to our multi-request
            curl_multi_add_handle($mh, $ch[$i]);
        }
        $curl=$ch[$i];
        $active = null;
        //execute the handles
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                usleep ( mt_rand( 100, 20000));
                //usleep ( mt_rand( 10000, 2000000));
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
        foreach($categories as $count => $category) {
            $response .= curl_multi_getcontent($ch[$count]);
        }
        $regexp_selected = "<div id=\"sch_result\">(.*)pg_page pg_end";
        $this->DebugLog("1 regexp_selected:".$regexp_selected);
        preg_match_all("/$regexp_selected/siU", $response, $matches, PREG_SET_ORDER);
        if (!empty($matches[0][1]) ) {
            $response = $matches[0][1];
        }
        $response  = preg_replace('/<h2>(.*)<\/h2>/siU',' ',$response);
        return $response;
    }
    private function getInfo($cate,$id)
    {
        $curl = curl_init();
        $this->DebugLog("cate :".$cate." "."id :".$id);
        $this->DebugLog("this->purl :".$this->purl);
        $url = sprintf($this->purl,$cate, $id);
        $this->DebugLog("url :".$url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER , true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        $response = curl_exec($curl);
        $response = str_replace("&","&amp;",$response);
        curl_close($curl);
        $regexp2 = "<header>(.*)<center>";
        $this->DebugLog("Crawling All regexp of ID :".$regexp2);
        $regexp_title = "title=[\"'](.*)[\"']>magnet:";
        $this->DebugLog("regexp_title :".$regexp_title);
        $regexp_hash = "";
        $regexp_download = "<a href=[\"'](magnet:\?xt=urn:btih:.*)&amp;dn=";
        $this->DebugLog("regexp_download :".$regexp_download);
        $regexp_size = "align=[\"']absmiddle[\"']>&amp;nbsp;&amp;nbsp;([0-9\,\.]+)(GB|MB|KB|TB)<\/div";
        $this->DebugLog("regexp_size :".$regexp_size);
        $regexp_date = "<span class=[\"']mw_basic_view_datetime media-date\"[\"']><span title=.*>(.*)<\/span><\/span>";
        $this->DebugLog("regexp_date :".$regexp_date);
        $res=0;
        preg_match_all("/$regexp2/siU", $response, $matches2, PREG_SET_ORDER);
        foreach($matches2 as $match2) :
            $res=$res+1;
            if($res==1){
                $this->DebugLog("match2[0] :".$match2[0]);
            }
            if(preg_match_all("/$regexp_title/siU", $match2[0], $matches, PREG_SET_ORDER)) {
                foreach($matches as $match) {
                    $info['title'] = $match[1];
                    $this->DebugLog("Title :".$match[1]);
                    $info['hash'] = md5($match[1]);
                    $this->DebugLog("hash :".md5($match[1]));
                }
            }
            if(preg_match_all("/$regexp_download/siU", $match2[0], $matches, PREG_SET_ORDER)) {
                foreach($matches as $match) {
                    $info['download'] = $match[1];
                    $this->DebugLog("download :".$match[1]);
                }
            }
            if(preg_match_all("/$regexp_size/siU", $match2[0], $matches, PREG_SET_ORDER)) {
                foreach($matches as $match) {
                     $size = str_replace(",",".",$match[1]);
                     switch (trim($match[2])){
                             case 'KB':
                                     $size = $size * 1024;
                                     break;
                             case 'MB':
                                     $size = $size * 1024 * 1024;
                                     break;
                             case 'GB':
                                     $size = $size * 1024 * 1024 * 1024;
                                     break;
                             case 'TB':
                                     $size = $size * 1024 * 1024 * 1024 * 1024;
                                     break;
                    }
                    $size = floor($size);
                    $info['size'] = $size;
                    $this->DebugLog("size :".$size);
                }
            }
            if(preg_match_all("/$regexp_date/siU", $match2[0], $matches, PREG_SET_ORDER)) {
                foreach($matches as $match) {
                    $info['date'] = $match[1];
                    $this->DebugLog("date :".$match[1]);
                }
            }
        endforeach;
        return $info;
    }
    public function parse($plugin, $response)
    {
        $this->DebugLog($plugin);
        $this->DebugLog("Parse response :".$response);
        $regexp = "<li>.?<a href=\".\/board.php\?bo_table=(.*)&amp;wr_id=(.*)\" class=\"sch_res_title\">.*<span class=\"sch_datetime\">(.*)<\/span>";
        $this->DebugLog("Parse regexp :".$regexp);
        $res=0;
        $check_record_num=0;
        if(preg_match_all("/$regexp/siU", $response, $matches, PREG_SET_ORDER)) {
            $title="Unknown title";
            $download="Unknown download";
            $size=0;
            $datetime="1900-12-31";
            $current_time= date('Y-m-d H:i:s');
            $page="Default page";
            $hash="Hash unknown";
            $seeds=0;
            $leechs=0;
            $category="Unknown category";
            //DB Data Check
            if ($this->Chk_Exists_in_DB==true) {
                $this->DB_Connect();
                $cnt_query_in_db = $this->check_query_in_db($this->query_value);
                $this->db_logger('Count query in db',$cnt_query_in_db);
                $this->DebugLog("Count query in db: $cnt_query_in_db ");
            }
            $rss  = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n";
            $rss .= "<rss xmlns:atom=\"http://www.w3.org/2005/Atom\" version=\"2.0\">\n";
            $rss .= "<channel>";
            $rss .= "<title>TOMOVIE ".$this->query_value." </title>";
            $rss .= "<link>" . "https://tomovie.net/" . "</link>";
            $rss .= "<description>TOMOVIE</description>\n";
            foreach($matches as $match) {
                $cate      = $match[1];
                $id        = $match[2];
                $date      = $match[3];
                $date      = date(r,strtotime($date));
                $info      = $this->getInfo($cate,$id);
                $title     = $info['title'];
                $download  = $info['download'];
                $size      = (isset($info['size']) && !is_null($info['size'])) ? $info['size'] : 0;
                $size      = round($size);
                $datetime  = $date;
                $hash      = md5($title);
                $category  = $cate;
                $seeds     = 0;
                $leechs    = 0;
                $page      = sprintf($this->purl,$cate, $id);
                $this->DebugLog("Parse status :".$status);
                $this->DebugLog("Parse title1 :".$titleHd);
                $this->DebugLog("Parse id :".$id);
                $this->DebugLog("Parse date :".$date);
                $this->DebugLog("Parse title :".$title);
                $this->DebugLog("Parse download :".$download);
                $this->DebugLog("Parse size :".$size);
                $this->DebugLog("Parse datetime :".$datetime);
                $this->DebugLog("Parse page :".$page);
                $this->DebugLog("Parse hash :".$hash);
                $this->DebugLog("Parse seeds :".$seeds);
                $this->DebugLog("Parse leechs :".$leechs);
                $this->DebugLog("Parse category :".$category);
                // DB Data Check
                if ($this->Chk_Exists_in_DB==true) {
                    if (!( $title == '' || $download == '' )) {
                        $check_record_num = $check_record_num +1;
                        $action = $this->check_item_db($title,$download,$check_record_num);
                        $this->db_logger('action check_item_db',$action);
                        $this->DebugLog("action check_item_db: $action ");
                        if (!($action==="ALLOW")) {
                            $download = '';//return 제외 처리.
                        }
                    }
                }
                if (!( $title == '' || $download == '' )) {
                    $this->DebugLog("If so Parse cate :".$cate);
                    $this->DebugLog("If so Parse id :".$id);
                    $rss .= "<item>\n";
                    $rss .= "<title>" . $title. "</title>";
                    $rss .= "<link>" . $download . "</link>";
                    $rss .= "<enclosure length="."\"$size\" />\n";
                    $rss .= "<pubDate>" . $datetime . "</pubDate>";
                    $rss .= "<category>" . $category . "</category>\n";
                    $rss .= "</item>\n";
                    $res++;
                } else {
                    $this->DebugLog("Else Parse cate :".$cate);
                    $this->DebugLog("Else Parse id :".$id);
                }
            }
            $rss .= "</channel>\n</rss>";
            $this->DebugLog("res :".$res);
        }
        //return $res;
        $this->DebugLog("rss :".$rss);
        if ($res == 0 ) {
            $rss = '';
        }
        return $rss;
    }
}
// sudo chown -R DownloadStation:DownloadStation /tmp/rss_tomovie.log
// sudo chmod -R 777  /tmp/rss_tomovie.log
// sudo chown -R DownloadStation:DownloadStation /volume1/web/rss/rss_tomovie.php
// sudo chmod 777  /volume1/web/rss/rss_tomovie.php
$k=$_GET["k"];
$multi = new SynoRSSTOMOVIE;
$txt = $multi->prepare(null,$k);
$returnRSS = $multi->parse($plugin, $txt);
echo $returnRSS;
?>