<?php
/*
 * This file is part of the NB Framework package.
 *
 * Copyright (c) 2018 https://nb.cx All rights reserved.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace service;

use deploy\Config;
use model\Poker;
use model\Room;
use nb\Service;
use util\Auth;
use util\Redis;

/**
 * Game
 *
 * @package service
 * @link https://nb.cx
 * @author: collin <collin@nb.cx>
 * @date: 2018/10/31
 */
class Game extends Service {

    //准备
    protected function ready() {
        $auth = Auth::init();

        if($auth->play != 'room') {
            $this->code = 4034;
            $this->msg = '无法准备游戏';
            return false;
        }

        $room = $auth->room; $seat = $auth->seat; $player = $room->$seat;
        $player['ready'] = $player['ready']?0:1;
        $room->$seat = $player;
        $this->data = $room;
        return true;
    }

    protected function start($room) {
        $shuffle = $this->shuffle();
        list($a,$b,$c,$d) = $shuffle;

        $pa = $room->a;$pa['poker'] = $a;$pa['ready'] = 0;$pa['multiple'] = 1;$pa['lead'] = [];

        $pb = $room->b;$pb['poker'] = $b;$pb['ready'] = 0;$pb['multiple'] = 1;$pb['lead'] = [];

        $pc = $room->c;$pc['poker'] = $c;$pc['ready'] = 0;$pc['multiple'] = 1;$pc['lead'] = [];

        $call = $room->win?:'a';

        Redis::hMset('room:'.$room->id,[
            'call'=>json_encode([
                'n'=>0, //叫地主次数
                'note'=>[], //每次
                'who'=>$call//该谁了
            ]),
            'start'=>time(),//游戏开始时间
            'status'=>'startd',
            'a'=>json_encode($pa),
            'b'=>json_encode($pb),
            'c'=>json_encode($pc),
            'pocket'=>json_encode($d), //底牌
        ]);

        $this->data = Room::get($room->id);

        return true;
    }

    //叫/抢地主
    protected function rob() {
        $auth = Auth::init();

        $room = $auth->room;
        $seat = $auth->seat;

        $call = $room->call;

        if($auth->seat !== $call['who']) {
            $this->code = 1;
            $this->msg = '还没临到你叫地主哦';
            return false;
        }

        $player = $room->$seat;

        $rob = $this->input('rob');


        if($rob) {
            $multiple = $player['multiple'] + 1;
            $new = ['multiple'=>$multiple];
            $room->$seat =$new;
        }

        $n = $call['n'];
        if($n < 2 || ($n < 3 && $call['note'][0][1])) {
            $call['note'][] = [$seat,$rob];
            //保存玩家的倍数
            $room->call = [
                'n'=>$n+1,
                'note'=>$call['note'],
                'who'=>$seat==='a'?'b':($seat==='b'?'c':'a')
            ];
            $this->data = $room;
            return true;
        }

        if(!$rob) {
            if($n == 2) {
                if($call['note'][1][1]) {
                    $seat = $call['note'][1][0];
                }
                else {
                    $seat = $call['note'][0][0];
                }
            }
            else {
                if($call['note'][2][1]) {
                    $seat = $call['note'][2][0];
                }
                elseif($call['note'][1][1]) {
                    $seat = $call['note'][1][0];
                }
            }
        }

        $player = $room->$seat;
        $poker = $player['poker'];
        foreach ($room->pocket as $k=>$v) {
            $poker[$k] = $v;
        }
        ksort($poker);
        $room->$seat = ['poker'=>$poker];

        //产生地主
        $room->landowner = $seat;
        $room->call = 0;

        $this->data = $room;
        return true;
    }

    //出牌
    protected function lead() {
        $input = $this->input('poker');
        $input = explode(',',$input);

        $auth = Auth::init(); $room = $auth->room; $seat = $auth->seat;

        //校验是否该其出牌
        if($room->leader !== $seat) {
            $this->code = 500;
            $this->msg = '还没有到出牌的时间';
            return false;
        }

        //过
        if(count($input) == 0 && $room->lead['seat'] != $auth->seat) {
            $room->leader = $seat==='a'?'b':($seat==='b'?'c':'a');
            $this->msg = '过';
            return true;
        }


        //校验要出的牌是否存在
        $player = $room->$seat; $remainder = $player['poker'];
        foreach ($input as $v) {
            if(isset($remainder[$v])) {
                unset($remainder[$v]);
                continue;
            }
            $this->code = 500;
            $this->msg = '非法出牌';
            return false;
        }

        $poker = new Poker($input);
        if(!$poker->compare($room->lead)) {//需要压过的牌
            $this->code = 500;
            $this->msg = '出牌错误';
            return false;
        }

        $this->data = $room;

        //检查剩余牌
        if(count($remainder) < 1) {
            //结束，赢了
            $room->win = $seat;
            $room->$seat = ['poker'=>$remainder];
            return true;
        }

        $room->$seat = [
            'poker'=>$remainder,
            'lead'=>$input
        ];

        $poker->seat = $seat;//记录出牌人
        $room->lead = $poker->mingle();
        return true;
    }

    protected function end($room) {

    }

    //洗牌
    private function shuffle(){
        $tmp = $arr = Config::$o->poker;
        foreach($arr as $k=>$v){
            $index = rand(0,54 - $k -1);
            $key = array_search($tmp[$index], $arr);
            $cards[$key] = $tmp[$index];
            unset($tmp[$index]);
            $tmp = array_values($tmp);
        }

        //玩家1
        $a = array_slice($cards, 3, 17, true);
        ksort($a);

        //玩家2
        $b =array_slice($cards, 20, 17, true);
        ksort($b);

        //玩家3
        $c = array_slice($cards, 37, 17, true);
        ksort($c);

        $d = array_slice($cards, 0, 3, true);
        ksort($d);

        return [$a,$b,$c,$d];
    }



}