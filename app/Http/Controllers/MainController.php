<?php namespace App\Http\Controllers;

use App\Http\Requests;
use App\Custom\Grab;
use App\Http\Controllers\Controller;

use Illuminate\Http\Request;

class MainController extends Controller {
    public function main(){
        $grab=new Grab('http://blog.sina.com.cn/s/blog_49818dcb0102vnyg.html?tj=1');
        $text=$grab->countPage();
        return $text;
    }

}
