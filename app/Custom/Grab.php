<?php namespace App\Custom;
include_once 'phpQuery.php';
class Grab
{
    /*
	|--------------------------------------------------------------------------
	| 网页分析及文本抓取类
	|--------------------------------------------------------------------------
	|1、现今网页都是基于div+css3，因此可以确定现今绝大多数的网页中的每块类容都是以div为基本模块
    |2、只是对中文网页进行抓取
    |3、在这里，网页类型分为列表页、正文页、其他页(活动宣传网页、图片展示网页等不包括以文字为信息载体的其他网页)
    |4、不论是列表页还是正文页，每个类型的网页都可以用一种数字模型来判断
    |5、不论是列表页还是内容页，网页所传递的信息都存在于div容器中
    |6、以div为基本单位对网页进行整理并统计处每个div中的文字个数、链接个数
    |7、根据文字个数与链接个数的比值与网页类型判断的阈值进行比较进而派别网页的类型
    |8、对于正文页，肯定存在一块区域存放网页的正文，而其他区域的文字就会少很多，
        因此就会造成方差波动巨大，根据这一特征可以确定网页正文所在哪一个div，进而取出该div的内容
	|
	*/
    protected $url;  //当前采集的页面
    protected $detectLen=0.6;  //探测整理数组的允许前进的百分比
    protected $limitWord=380;  //采集结果中允许的最少中文个数
    protected $thre=100;       //网页类型判断的阈值
    protected $graDiff=0.55;   //相邻两个元素的梯度差

    public function __construct($url){
        $this->url=$url;
    }

    public function countPage(){
        $div_aTags_num=array();   //统计每个div中的a标签个数
        $div_char_num=array();    //统计每个div中的文本中的中文个数
        $div_content=array();     //保存每个div中的文本
        $char_aTags=array();      //保存每个div中汉字个数与链接的比值
        //初始化网页
        \phpQuery::newDocumentFile($this->url);
        //统计网页
        $divs=pq('div');
        foreach($divs as $div){
            array_push($div_aTags_num,count(pq($div)->find('a')));
            array_push($div_char_num,self::chaneseCount(pq($div)->text()));
            array_push($div_content,pq($div)->text());
        }
        //计算每个div中汉字个数与a标签的个数的比值
        for($i=0;$i<count($div_aTags_num);$i++){
            if($div_aTags_num[$i]==0||$div_char_num[$i]==0){
                $val=$div_char_num[$i];
            }
            else{
                $val=round($div_char_num[$i]/$div_aTags_num[$i]);
            }
            array_push($char_aTags,$val);
        }

        //获取网页的方差特征
        $variance=self::variance($char_aTags);
        //如果该网页是内容页
        if($variance>$this->thre){
            $index=self::getMainContent($div_char_num);
            if(isset($index)){
                for($i=0;$i<count($index);$i++){
                    $text=strip_tags($div_content[$index[$i]]);
                    if(self::chaneseCount($text)>$this->limitWord){
                        \phpQuery::$documents=array();
                        echo $text;die();
                        return $text;
                    }
                }
            }
        }
    }


    /**
     * 确定网页正文最可能存在的div索引
     * @param $array
     * @return array
     */
    private function getMainContent($array){
        $gra_diff=$this->graDiff;
        //对保存每个div中的汉字个数的数组进行整理
        $newArray=self::orderArray($array);
        $selected=array();
        $variance=array();
        //对整理后的数组进行备份
        $copyNew=$newArray;
        //求原数组的平均值
        $avg=array_sum($newArray)/count($newArray);
        //对整理后的数组进行逆序排序
        rsort($copyNew);
        //一般认为只是数组的前一半有效
        for($i=0;$i<ceil(count($copyNew))/2;$i++){
            //计算元素相对于平均值的偏离度
            $percent=round(1-$avg/$copyNew[$i],2);
            if($percent>$gra_diff){
                array_push($selected,$copyNew[$i]);
            }
        }
        //计算整理后的数组的方差放在数组的首部
        array_push($variance,self::variance($newArray));
        for($i=0;$i<count($selected);$i++){
            $temp=$newArray;
            //寻找当前元素在原数组中的位置
            $key=array_search($selected[$i],$temp);
            //在原数组的删除当前元素
            array_splice($temp,$key,1);
            //计算删除当前元素后元素数组的方差
            array_push($variance,self::variance($temp));
        }

        //计算删除每一个元素后数组的方差相对于未删除时方差的偏离度
        for($i=1;$i<count($variance);$i++){
            $variance[$i]=round(($variance[0]-$variance[$i])/$variance[0],2);
        }
        //去掉数组首部的原始值
        array_splice($variance,0,1);
        $copy_var=$variance;
        //为什么是升序？在筛选出来的元素中，如果去掉它对整体的方差影响较小，则认为它是
        //比较稳定的，所以网页的正文也就很可能存在该元素所对应的div中
        sort($copy_var);

        $selectedKey=array();
        //寻找当前元素在原始数组中对应的索引
        for($i=0,$num=0;$i<count($copy_var);$i++,$num++){
            if($num<2&&$copy_var[$i]>0){
                $key_in_varianc=array_search($copy_var[$i],$variance);
                array_push($selectedKey,array_search($selected[$key_in_varianc],$array));
            }
        }
        return $selectedKey;
    }

    /**
     *整理数组，把嵌套统计的div给去掉，以确定那个div对方差的贡献最大
     * @param $array 传入数组
     * @return mixed
     */
    private function orderArray($array){
        $len = count($array);
        $persent = $this->detectLen;
        //print_r($array);die();
        for ($i = $len - 1; $i > 0;) {
            $delen = floor($i * $persent); //下取整，防止最后几次为1
            for ($j = $i - 1; $i - $j <= $delen; $j--) { //这种试探的方式当i=1的时候($i-$j)==$delen不成立
                if ($array[$i] == $array[$j]) {
                    //array_splice($array, $j, 1);
                    $array[$j]='';
                }
            }
            $i=$j;
        }
        return $array;
    }

    /**
     * 计算数据的方差
     * @param $arr 传入数组
     *
     * @return string
     */
    private function variance($arr){
        $average = array_sum($arr) / count($arr);
        $sum = 0;
        foreach ($arr as $val) {
            $sum+= pow((abs($val - $average)) , 2);
        }
        //四舍五入保留两位小数
        return sprintf('%.2f', $sum / count($arr));
    }

    /**
     * 检测传入字符是否是中文
     * @param $str 传入字符
     * @return bool
     */
    private function isChanese($str){
        if (preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $str)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 统计传入字符中的中文个数
     * @param $str 传入字符串
     */
    private function chaneseCount($str){
        $count=0;
        for($i=0;$i<mb_strlen($str,'utf-8');$i++){
            if (self::isChanese(mb_substr($str, $i, 1, 'utf-8'))) {
                $count++;
            }
        }
        return $count;
    }
}

