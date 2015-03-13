<?php namespace App\Custom;
/*
 * 居于Unicode编码词典的php分词器
 *  1、只适用于php5，必要函数 iconv
 *  2、本程序是使用RMM逆向匹配算法进行分词的，词库需要特别编译，本类里提供了 MakeDict() 方法
 *  3、简单操作流程： SetSource -> StartAnalysis -> Get***Result
 *
 * Copyright IT柏拉图  QQ: 2500875 Email: dbzllx#21cn.com
 *
 */
define('_SP_', chr(0xFF).chr(0xFE)); 
class PhpAnalysis
{
    
    //输入和输出的字符编码（只允许 utf-8、gbk/gb2312/gb18030、big5 三种类型）  
    public $sourceCharSet = 'utf-8';
    public $targetCharSet = 'utf-8';
	
    //生成的分词结果数据类型 1 为全部， 2为 词典词汇及单个中日韩简繁字符及英文， 3 为词典词汇及英文
    public $resultType = 1;
    
    //句子长度小于这个数值时不拆分，notSplitLen = n(个汉字) * 2 + 1
    public $notSplitLen = 5;
    
    //把英文单词全部转小写
    public $toLower = false;
    
    //使用最大切分模式对二元词进行消岐
    public $differMax = false;
    
    //使用热门词优先模式进行消岐
    public $differFreq = false;
	
    //被转换为unicode的源字符串
    private $sourceString = '';
    
    //附加词典
    public $dicStr = '';
    public $addonDic = array();
    public $addonDicFile = 'dict/words_addons.dic';
    
    //主词典 
    public $mainDic = array();
    public $mainDicFile = 'dict/base_dic.dic';
    //是否直接载入词典（选是载入速度较慢，但解析较快；选否载入较快，但解析较慢，需要时才会载入特定的词条）
    private $isLoadAll = false;
    
    //主词典词语最大长度(实际加上词末为12+2)
    private $dicWordMax = 12;
    //粗分后的数组（通常是截取句子等用途）
    private $simpleResult = array();
    //最终结果(用空格分开的词汇列表)
    private $finallyResult = '';
    
    //是否已经载入词典
    public $isLoadDic = false;
    //系统识别或合并的新词
    public $foundWord = '';
    //词库载入时间
    public $loadTime = 0;
	
    /**
     * 构造函数
     * @param $source_charset
     * @param $target_charset
     * @param $source
     *
     * @return void
     */
    public function __construct($source_charset='utf-8', $target_charset='utf-8', $load_all=true, $source='')
    {
        $this->SetSource( $source, $source_charset, $target_charset );
        $this->isLoadAll = $load_all;
        $this->LoadDict();
    }
    
    /**
     * 设置源字符串
     * @param $source
     * @param $source_charset
     * @param $target_charset
     *
     * @return bool
     */
    public function SetSource( $source, $source_charset='utf-8', $target_charset='utf-8' )
    {
        $this->sourceCharSet = strtolower($source_charset);
        $this->targetCharSet = strtolower($target_charset);
        $this->simpleResult = array();
        $this->finallyResult = array();
        $this->finallyIndex = array();
        if( $source != '' )
        {
            $rs = true;
            if( preg_match("/^utf/", $source_charset) ) {
                $this->sourceString = iconv('utf-8', 'ucs-2', $source);
            }
            else if( preg_match("/^gb/", $source_charset) ) {
                $this->sourceString = iconv('utf-8', 'ucs-2', iconv('gb18030', 'utf-8', $source));
            }
            else if( preg_match("/^big/", $source_charset) ) {
                $this->sourceString = iconv('utf-8', 'ucs-2', iconv('big5', 'utf-8', $source));
            }
            else {
                $rs = false;
            }
        }
        else
        {
           $rs = false;
        }
        return $rs;
    }
    
    /**
     * 设置结果类型(只在获取finallyResult才有效)
     * @param $rstype 1 为全部， 2去除特殊符号
     *
     * @return void
     */
    public function SetResultType( $rstype )
    {
        $this->resultType = $rstype;
    }
    
    /**
     * 载入词典
     *
     * @return void
     */
    public function LoadDict( $maindic='' )
    {

        $dicAddon = dirname(__FILE__).'/'.$this->addonDicFile;
        $dicWords = ( $maindic=='' || !file_exists(dirname(__FILE__).'/'.$maindic) ) ? dirname(__FILE__).'/'.$this->mainDicFile : dirname(__FILE__).'/'.$maindic;
        //载入主词典
        $startt = microtime(true);
        $aslen = filesize($dicWords);
        $fp = fopen($dicWords, 'rb');
        $this->dicStr = fread($fp, $aslen);
        fclose($fp);
        $ishead = 1;
        $nc = '';
        $i = 0;
        while( $i < $aslen )
        {
            $nc = substr($this->dicStr, $i, 2);
            $i = $i+2;
            $slen = hexdec(bin2hex( substr($this->dicStr, $i, 2) ));
            $i = $i+2;
            $this->mainDic[$nc]['w'] = '';
            $this->mainDic[$nc]['p'][0] = $i;
            $this->mainDic[$nc]['p'][1] = $slen;
            if( $this->isLoadAll )
            {
                $strs = explode(_SP_, substr($this->dicStr, $i, $slen) );
                foreach($strs as $w)
                {
                    $this->mainDic[$nc]['w'][$w] = 1;
                }
            }
            $i = $i + $slen;
        }
        if($this->isLoadAll)
        {
            $this->dicStr = '';
        }
        //载入副词典
        $ds = file($dicAddon);
        foreach($ds as $d)
        {
            $d = trim($d);
            if($d=='') continue;
            $estr = substr($d, 1, strlen($d)-1);
            $estr = iconv('utf-8', 'ucs-2', $estr);
            $this->addonDic[substr($d, 0, 1)][$estr] = strlen($estr);
        }
        $this->loadTime = microtime(true) - $startt;
        $this->isLoadDic = true;
    }
    
    /**
    * 检测某个尾词是否存在
    */
    public function IsWordEnd($nc)
    {
         if( !isset( $this->mainDic[$nc] ) )
         {
            return false;
         }
         if( !is_array($this->mainDic[$nc]['w']) )
         {       
              $strs = explode(_SP_, substr($this->dicStr, $this->mainDic[$nc]['p'][0], $this->mainDic[$nc]['p'][1]) );
              foreach($strs as $w)
              {
                    $this->mainDic[$nc]['w'][$w] = 1;
              }
         }
         return true;
    }
    
    /**
     * 开始执行分析
     * @parem bool optimize 是否对结果进行优化
     * @return bool
     */
    public function StartAnalysis($optimize=true)
    {
        if( !$this->isLoadDic )
        {
            $this->LoadDict();
        }
        $this->simpleResult = $this->finallyResult = array();
        $this->sourceString .= chr(0).chr(32);
        $slen = strlen($this->sourceString);
        $sbcArr = array();
        $j = 0;
        //全角与半角字符对照表
        for($i=0xFF00; $i < 0xFF5F; $i++)
        {
            $scb = 0x20 + $j;
            $j++;
            $sbcArr[$i] = $scb;
        }
        //对字符串进行粗分
        $onstr = '';
        $lastc = 1; //1 中/韩/日文, 2 英文/数字/符号('.', '@', '#', '+'), 3 ANSI符号 4 纯数字 5 非ANSI符号或不支持字符
        $s = 0;
        $ansiWordMatch = "[0-9a-z@#%\+\.]";
        $notNumberMatch = "[a-z@#%\+]";
        for($i=0; $i < $slen; $i++)
        {
            $c = $this->sourceString[$i].$this->sourceString[++$i];
            $cn = hexdec(bin2hex($c));
            $cn = isset($sbcArr[$cn]) ? $sbcArr[$cn] : $cn;
            //ANSI字符
            if($cn < 0x80)
            {
                if( preg_match('/'.$ansiWordMatch.'/i', chr($cn)) )
                {
                    if( $lastc != 2 && $onstr != '') {
                        $this->simpleResult[$s]['w'] = $onstr;
                        $this->simpleResult[$s]['t'] = $lastc;
                        $this->DeepAnalysis($onstr, $lastc, $s);
                        $s++;
                        $onstr = '';
                    }
                    $lastc = 2;
                    $onstr .= chr(0).chr($cn);
                }
                else
                {
                    if( $onstr != '' )
                    {
                        $this->simpleResult[$s]['w'] = $onstr;
                        if( $lastc==2 )
                        {
                            if( !preg_match('/'.$notNumberMatch.'/i', iconv('ucs-2', 'utf-8', $onstr)) ) $lastc = 4;
                        }
                        $this->simpleResult[$s]['t'] = $lastc;
                        if( $lastc != 4 ) $this->DeepAnalysis($onstr, $lastc, $s);
                        $s++;
                    }
                    $onstr = '';
                    $lastc = 3;
                    if($cn < 31)
                    {
                        continue;
                    }
                    else
                    {
                        $this->simpleResult[$s]['w'] = chr(0).chr($cn);
                        $this->simpleResult[$s]['t'] = 3;
                        $s++;
                    }
                }
            }
            //普通字符
            else
            {
                //正常文字
                if( ($cn>0x3FFF && $cn < 0x9FA6) || ($cn>0xF8FF && $cn < 0xFA2D)
                    || ($cn>0xABFF && $cn < 0xD7A4) || ($cn>0x3040 && $cn < 0x312B) )
                {
                    if( $lastc != 1 && $onstr != '')
                    {
                        $this->simpleResult[$s]['w'] = $onstr;
                        if( $lastc==2 )
                        {
                            if( !preg_match('/'.$notNumberMatch.'/i', iconv('ucs-2', 'utf-8', $onstr)) ) $lastc = 4;
                        }
                        $this->simpleResult[$s]['t'] = $lastc;
                        if( $lastc != 4 ) $this->DeepAnalysis($onstr, $lastc, $s);
                        $s++;
                        $onstr = '';
                    }
                    $lastc = 1;
                    $onstr .= $c;
                }
                //特殊符号
                else
                {
                    if( $onstr != '' )
                    {
                        $this->simpleResult[$s]['w'] = $onstr;
                        if( $lastc==2 )
                        {
                            if( !preg_match('/'.$notNumberMatch.'/i', iconv('ucs-2', 'utf-8', $onstr)) ) $lastc = 4;
                        }
                        $this->simpleResult[$s]['t'] = $lastc;
                        if( $lastc != 4 ) $this->DeepAnalysis($onstr, $lastc, $s);
                        $s++;
                    }
                    
                    //检测书名
                    if( $cn == 0x300A )
                    {
                        $tmpw = '';
                        $n = 1;
                        $isok = false;
                        $ew = chr(0x30).chr(0x0B);
                        while(true)
                        {
                            $w = $this->sourceString[$i+$n].$this->sourceString[$i+$n+1];
                            if( $w == $ew )
                            {
                                $this->simpleResult[$s]['w'] = $c;
                                $this->simpleResult[$s]['t'] = 5;
                                $s++;
                        
                                $this->simpleResult[$s]['w'] = $tmpw;
                                $this->foundWord .= $this->OutStringEncoding($tmpw).'/b, ';
                                $this->simpleResult[$s]['t'] = 13;
                                $s++;
                        
                                $this->simpleResult[$s]['w'] = $ew;
                                $this->simpleResult[$s]['t'] =  5;
                                $s++;
                        
                                $i = $i + $n + 1;
                                $isok = true;
                                $onstr = '';
                                $lastc = 5;
                                break;
                            }
                            else
                            {
                                $n = $n+2;
                                $tmpw .= $w;
                                if( strlen($tmpw) > 60 )
                                {
                                    break;
                                }
                            }
                        }//while
                        if( !$isok )
                        {
                            $this->simpleResult[$s]['w'] = $c;
              	            $this->simpleResult[$s]['t'] = 5;
              	            $s++;
              	            $onstr = '';
                            $lastc = 5;
                        }
                        continue;
                    }
                    
                    $onstr = '';
                    $lastc = 5;
                    if( $cn==0x3000 )
                    {
                        continue;
                    }
                    else
                    {
                        $this->simpleResult[$s]['w'] = $c;
                        $this->simpleResult[$s]['t'] = 5;
                        $s++;
                    }
                }//2byte symbol
                
            }//end 2byte char
        
        }//end for
        
        //处理分词后的结果
        $this->OptimizeResult($optimize);
    }
    
    /**
     * 深入分词
     * @parem $str
     * @parem $ctype (2 英文类， 3 中/韩/日文类)
     * @parem $spos   当前粗分结果游标
     * @return bool
     */
    private function DeepAnalysis( &$str, $ctype, $spos )
    {

        //中文句子
        if( $ctype==1 )
        {
            $slen = strlen($str);
            $this->DeepAnalysisChinese( $str, $ctype, $spos, $slen );
            return ;
            //小于系统配置分词要求长度的句子
            if( $slen < $this->notSplitLen )
            {
                $tmpstr = '';
                $lastType = 0;
                if( $spos > 1 ) $lastType = $this->simpleResult[$spos-1]['t'];
                
                //如果词典有的词，不进行后面的检测
                if( isset($this->mainDic[substr($str, $slen-2, 2)]['w'][substr($str, 2, $slen-2)]) || $slen < 5 )
                {
                    $this->finallyResult[$spos][] = $str;
                    return ;
                }
                
                //停止词检测
                if( isset( $this->addonDic['s'][substr($str, 0, 2)] ) )
                {
                    $this->finallyResult[$spos][] = substr($str, 0, 2);
                    $this->finallyResult[$spos][] = substr($str, 2, $slen-2);
                }
                else if( isset( $this->addonDic['s'][substr($str, $slen-2, 2)] ) )
                {
                    $this->finallyResult[$spos][] = substr($str, $slen-2, 2);
                    $this->finallyResult[$spos][] = substr($str, 0, $slen-2);
                }    
                else {
                    $this->finallyResult[$spos][] = $str;
                }
                
                return ;
            }
            //正常长度的句子，循环进行分词处理
            else
            {
                $this->DeepAnalysisChinese( $str, $ctype, $spos, $slen );
            }
        }
        //英文句子，转为小写
        else
        {
            if( $this->toLower ) {
                $this->finallyResult[$spos][] = strtolower($str);
            }
            else {
                $this->finallyResult[$spos][] = $str;
            }
        }
    }
    
    /**
     * 中文的深入分词
     * @parem $str
     * @return void
     */
    private function DeepAnalysisChinese( &$str, $lastec, $spos, $slen )
    {
        $tmparr = array();
        $hasw = 0;
        for($i=$slen-1; $i>0; $i--)
        {
            $nc = $str[$i-1].$str[$i];
            if($i<2)
            {
                $tmparr[] = $nc;
                $i = 0;
                break;
            }
            if( $this->IsWordEnd($nc) )
            {
                $i = $i - 1;
                $isok = false;
                for($k=12; $k>1; $k=$k-2)
                {
                    //if($i < $k || $this->mainDic[$nc]['t'][$k]==0) continue;
                    if($i < $k) continue;
                    $w = substr($str, $i-$k, $k);
                    //echo iconv('ucs-2', 'utf-8', $w.$nc)."<br/>\n";
                    if( isset($this->mainDic[$nc]['w'][$w]) )
                    {
                        $tmparr[] = $w.$nc;
                        $i = $i - $k;
                        $isok = true;
                        break;
                    }
                }
                if(!$isok)
                {
                   $tmparr[] = $nc;
                }
            }
            else
            {
               $tmparr[] = $nc;
               $i = $i - 1;
            }
        }
        if(count($tmparr)==0) return ;
        for($i=count($tmparr)-1; $i>=0; $i--)
        {
            $this->finallyResult[$spos][] = $tmparr[$i];
        }
    }
    
    /**
    * 转换最终分词结果到 finallyResult 数组
    * @return void
    */
    private function SortFinallyResult()
    {
    	  $newarr = array();
        $i = 0;
        foreach($this->simpleResult as $k=>$v)
        {
            if( isset($this->finallyResult[$k]) )
            {
                foreach($this->finallyResult[$k] as $w)
                {
                    if(!empty($w))
                    {
                    	$newarr[$i]['w'] = $w;
                    	$newarr[$i]['t'] = 20;
                    	$i++;
                    }
                }
            }
            else
            {
                $newarr[$i]['w'] = $v['w'];
                $newarr[$i]['t'] = $v['t'];
                $i++;
            }
        }
        $this->finallyResult = $newarr;
        $newarr = '';
  	}
    
    /**
    * 对最终分词结果进行优化（把simpleresult结果合并，并尝试新词识别、数词合并等）
    * @parem $optimize 是否优化合并的结果
    * @return bool
    */
    private function OptimizeResult($optimize=true)
    {
        $this->SortFinallyResult();
        if( !$optimize ) return ;
        $newarr = array();
        $cwn = count($this->finallyResult);
        $j = 0;
        for($i=0; $i < $cwn; $i++)
        {
            if( !isset($this->finallyResult[$i+1]) )
            {
                $newarr[$j]['w'] = $this->finallyResult[$i]['w'];
                $newarr[$j]['t'] = $this->finallyResult[$i]['t'];
                break;
            }
            $cw = $this->finallyResult[$i]['w'];
            $nw = $this->finallyResult[$i+1]['w'];
            $ischeck = false;
            //检测数量词
            if( $this->finallyResult[$i]['t']==4 || isset( $this->addonDic['c'][$cw] ) )
            {
                if( isset( $this->addonDic['u'][$nw] ) )
                {
                    $newarr[$j]['w'] = $cw.$nw;
                    $this->foundWord .= $this->OutStringEncoding($newarr[$j]['w']).'/nu, ';
                    $newarr[$j]['t'] = 14;
                    $i++;
                    $ischeck = true;
                }
            }
            //检测前导词(通常是姓)
            else if( isset( $this->addonDic['n'][$cw] ) )
            {
                if( $this->finallyResult[$i+1]['t']==1 && !isset($this->addonDic['s'][$nw]) && strlen($nw)<5 )
                {
                    $newarr[$j]['w'] = $cw.$nw;
                    //尝试检测第三个词
                    if( strlen($nw)==2 && isset($this->finallyResult[$i+2]['w']) 
                    && $this->finallyResult[$i+2]['t']==1 && strlen($this->finallyResult[$i+2]['w'])==2
                    && !isset($this->addonDic['s'][ $this->finallyResult[$i+2]['w'] ]) )
                    {
                        $newarr[$j]['w'] .= $this->finallyResult[$i+2]['w'];
                        $i++;
                    }
                    $this->foundWord .= $this->OutStringEncoding($newarr[$j]['w']).'/n, ';
                    $newarr[$j]['t'] = 11;
                    $i++;
                    $ischeck = true;
                }
            }
            //检测后缀词(地名等)
            else if( isset($this->addonDic['a'][$nw]) )
            {
                if( !isset($this->addonDic['n'][$cw]) && $this->finallyResult[$i]['t']==1 && !isset($this->addonDic['s'][$cw]) )
                {
                    $newarr[$j]['w'] = $cw.$nw;
                    $this->foundWord .= $this->OutStringEncoding($newarr[$j]['w']).'/s, ';
                    $newarr[$j]['t'] = 11;
                    $i++;
                    $ischeck = true;
                }
            }
            //正常词或尝试合并新词
            else
            {
                if( strlen($cw)==2 && strlen($nw)==2 && 
                  !isset( $this->addonDic['s'][$cw] ) && !isset( $this->addonDic['s'][$nw] ) && 
                	$this->finallyResult[$i]['t'] == 1 && $this->finallyResult[$i+1]['t'] == 1 )
                {
                	 $newarr[$j]['w'] = $cw.$nw;
                	 $this->foundWord .= $this->OutStringEncoding($newarr[$j]['w']).'/m, ';
              		 $newarr[$j]['t'] = 11;
              		 $i++;
              		 $ischeck = true;
              	}
            }
            //不符合规则
            if( !$ischeck )
            {
                $newarr[$j]['w'] = $cw;
              	$newarr[$j]['t'] = $this->finallyResult[$i]['t'];
              	//二元消岐处理——最大切分模式
                if( $this->differMax && $newarr[$j]['t']==20 
                && strlen($cw) < 9 && strlen($cw) > 3  &&  $j-1 > 0 && strlen($newarr[$j-1]['w']) < 5 )
                {
                    $slen = strlen($cw);
                    $hasDiff = false;
                    $maxlen = ($slen > 4 ? $slen - 2 : 2);
                    $prew = $newarr[$j-1]['w'];
                    for($y=2; $y <= $maxlen; $y=$y+2)
                    {
                        $nh = substr($cw, $y-2, 2);
                        $nw = $prew.substr($cw, 0, $y-2);
                        if( $this->IsWordEnd($nh) && isset( $this->mainDic[$nh]['w'][$nw] ) )
                        {
                            if( !$hasDiff ) $j--;
                            $hasDiff = true;
                            $newarr[$j]['w'] = $nw.$nh;
                            $newarr[$j]['t'] = 20;
                            $j++;
                        }
                    }
                    if( $hasDiff )
                    {
                        $newarr[$j]['w'] = $cw;
                        $newarr[$j]['t'] = 20;
                    }
                }
            }
            $j++;
        }//End for
        $this->finallyResult = $newarr;
    }
    
    /**
     * 把uncode字符串转换为输出字符串
     * @parem str
     * return string
     */
     private function OutStringEncoding( &$str )
     {
        $rsc = $this->SourceResultCharset();
        if( $rsc==1 ) {
            $rsstr = iconv('ucs-2', 'utf-8', $str);
        }
        else if( $rsc==2 ) {
            $rsstr = iconv('utf-8', 'gb18030', iconv('ucs-2', 'utf-8', $str) );
        }
        else{
            $rsstr = iconv('utf-8', 'big5', iconv('ucs-2', 'utf-8', $str) );
        }
        return $rsstr;
     }
    
    /**
     * 获取最终结果字符串（用空格分开后的分词结果）
     * @return string
     */
     public function GetFinallyResult($spword=' ', $word_meanings=false)
     {
        $rsstr = '';
        foreach($this->finallyResult as $v)
        {
            if( $this->resultType==2 && ($v['t']==3 || $v['t']==5) )
            {
            	continue;
            }
            $w = $this->OutStringEncoding($v['w']);
            if( $w != ' ' )
            {
                if($word_meanings) $rsstr .= $spword.$w.'/'.$v['t'];
                else $rsstr .= $spword.$w;
            }
        }
        return $rsstr;
     }
     
    /**
     * 获取粗分结果，不包含粗分属性
     * @return array()
     */
     public function GetSimpleResult()
     {
        $rearr = array();
        foreach($this->simpleResult as $k=>$v)
        {
            $w = $this->OutStringEncoding($v['w']);
            if( $w != ' ' ) $rearr[] = $w;
        }
        return $rearr;
     }
     
    /**
     * 获取粗分结果，包含粗分属性（1中文词句、2 ANSI词汇（包括全角），3 ANSI标点符号（包括全角），4数字（包括全角），5 中文标点或无法识别字符）
     * @return array()
     */
     public function GetSimpleResultAll()
     {
        $rearr = array();
        foreach($this->simpleResult as $k=>$v)
        {
            $w = $this->OutStringEncoding($v['w']);
            if( $w != ' ' )
            {
                $rearr[$k]['w'] = $w;
                $rearr[$k]['t'] = $v['t'];
            }
        }
        return $rearr;
     }
     
    /**
     * 获取索引hash数组
     * @return array('word'=>count,...)
     */
     public function GetFinallyIndex()
     {
        $rearr = array();
        foreach($this->finallyResult as $v)
        {
            if( $this->resultType==2 && ($v['t']==3 || $v['t']==5) )
            {
            	continue;
            }
            $w = $this->OutStringEncoding($v['w']);
            if( $w == ' ' )
            {
                continue;
            }
            if( isset($rearr[$w]) )
            {
            	 $rearr[$w]++;
            }
            else
            {
            	 $rearr[$w] = 1;
            }
        }
        return $rearr;
     }
     
    /**
     * 获得保存目标编码
     * @return int
     */
     private function SourceResultCharset()
     {
        if( preg_match("/^utf/", $this->targetCharSet) ) {
           $rs = 1;
        }
        else if( preg_match("/^gb/", $this->targetCharSet) ) {
           $rs = 2;
        }
        else if( preg_match("/^big/", $this->targetCharSet) ) {
           $rs = 3;
        }
        else {
            $rs = 4;
        }
        return $rs;
     }
     
     /**
     * 导出词典的词条
     * @parem $targetfile 保存位置
     * @return void
     */
     public function ExportDict( $targetfile )
     {
        $fp = fopen($targetfile, 'w');
        foreach($this->mainDic as $k=>$v)
        {
            $k = iconv('ucs-2', 'utf-8', $k);
            foreach( $v['w'] as $wk => $wv)
            {
                $wk = iconv('ucs-2', 'utf-8', $wk);
                fwrite($fp, $wk.$k."\n");
            }
        }
        fclose($fp);
     }
     
     /**
     * 追加新词到内存里的词典
     * @parem $word unicode编码的词
     * @return void
     */
     public function AddNewWord( $word )
     {
        
     }
     
     /**
     * 编译词典
     * @parem $sourcefile utf-8编码的文本词典数据文件<参见范例dict/words.txt>
     * @return void
     */
     public function MakeDict( $sourcefile, $maxWordLen=16, $target='' )
     {
        if( $target=='' )
        {
            $dicWords = dirname(__FILE__).'/'.$this->mainDicFile;
        }
        else
        {
            $dicWords = dirname(__FILE__).'/'.$target;
        }
        $narr = $earr = array();
        if( !file_exists($sourcefile) )
        {
            echo 'File: '.$sourcefile.' not found!';
            return ;
        }
        $ds = file($sourcefile);
        $i = 0;
        $maxlen = 0;
        foreach($ds as $d)
        {
            $d = trim($d);
            if($d=='') continue;
            $d = iconv('utf-8', 'ucs-2', $d);
            //$d = mb_convert_encoding($d, 'ucs-2', 'utf-8');
            $nlength = strlen($d)-2;
            if( $nlength >= $maxWordLen ) continue;
            $maxlen = $nlength > $maxlen ? $nlength : $maxlen;
            $endc = substr($d, $nlength, 2);
            $n = hexdec(bin2hex($endc));
            if( isset($narr[$endc]) )
            {
                $narr[$endc]['w'][$narr[$endc]['c']] = $this->GetWord($d);
                $narr[$endc]['o'][$narr[$endc]['c']] = iconv('ucs-2', 'utf-8', $d);
                //$narr[$endc]['o'][$narr[$endc]['c']] = mb_convert_encoding($d, 'ucs-2', 'utf-8');
                $narr[$endc]['l'] += $nlength;
                $narr[$endc]['c']++;
                $narr[$endc]['h'][$nlength] = isset($narr[$endc]['h'][$nlength]) ? $narr[$endc]['h'][$nlength]+1 : 1;
            }
            else
            {
                $narr[$endc]['w'][0] = $this->GetWord($d);
                $narr[$endc]['o'][0] = iconv('ucs-2', 'utf-8', $d);
                //$narr[$endc]['o'][0] = mb_convert_encoding($d, 'ucs-2', 'utf-8');
                $narr[$endc]['l'] = $nlength;
                $narr[$endc]['c'] = 1;
                $narr[$endc]['h'][$nlength] = 1;
            }
        }
        $alllen = $n = $max = $bigw = $bigc = 0;
        $fp = fopen($dicWords, 'wb');
        foreach($narr as $k=>$v)
        {
            fwrite($fp, $k);
            /*
            for($i=2; $i <= 12; $i = $i+2)
            {
                if( empty($v['h'][$i]) ) {
                    fwrite($fp, chr(0).chr(0));
                }
                else {
                    fwrite($fp, pack('n', $v['h'][$i]));
                }
            }*/
            $allstr = '';
            foreach($v['w']  as $w)
            {
                $allstr .= $allstr=='' ? $w : _SP_.$w;
            }
            $alLen = strlen($allstr);
            $max = $alLen > $max ? $alLen : $max;
            fwrite($fp, pack('n', $alLen) );
            fwrite($fp, $allstr);
        }
        fclose($fp);
     }
     
     /**
     * 获得词的前部份
     * @parem $str 单词
     * @return void
     */
     private function GetWord($str)
     {
        $newstr = '';
        for($i=0; $i < strlen($str)-3; $i++)
        {
            $newstr .= $str[$i];
            $newstr .= $str[$i+1];
            $i++;
        }
        return $newstr;
     }
    
}

?> 