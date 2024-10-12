<?php
namespace SharkyDog\HTTP\Helpers;
use SharkyDog\HTTP;
use SharkyDog\HTTP\Log;
use Evenement\EventEmitter;

class MultipartBuffer extends EventEmitter {
  private $_bndl;
  private $_bndr;
  private $_bndp;
  private $_cntr = 0;
  private $_done = false;
  private $_buff = "\n";
  private $_mbff;

  public function __construct(string $boundary) {
    $this->_bndr = '--'.$boundary;
    $this->_bndl = strlen($this->_bndr);
    $this->_bndp = preg_quote($this->_bndr);

    $this->_mbff = new HTTP\MessageBuffer;

    $this->_mbff->on('headers', function($hdrs) {
      $hdrs = preg_split("#\r?\n#", $hdrs);
      $headers = [];

      foreach($hdrs as $hdr) {
        if(!preg_match("#^([^\:\s]+)\s*\:\s*(.*)$#", $hdr, $m)) {
          continue;
        }
        $name = implode('-',array_map('ucfirst',explode('-',trim($m[1]))));
        $headers[$name] = trim($m[2]);
      }

      $this->emit('part', [$headers, $this->_cntr]);
    });

    $this->_mbff->on('data', function($data) {
      $this->emit('data', [$data, $this->_cntr]);
    });

    $this->_mbff->on('error', function($code,$msg) {
      $this->_done = true;
      $this->emit('error', [$code, $msg, $this->_cntr]);
      $this->close();
    });
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function feed(string $data) {
    if($this->_done) {
      return;
    }

    if(!strlen($data)) {
      return;
    }

    $this->_buff .= $data;
    $data = '';
    $feed = '';
    $bndr = false;

    if(preg_match(
      '#\r?\n'.$this->_bndp.'(\-{2}|\r?\n)#',
      $this->_buff,
      $m, PREG_OFFSET_CAPTURE
    )) {
      $bndr = true;
      $this->_done = $m[1][0] == '--';

      if($m[0][1]) {
        $data = substr($this->_buff, 0, $m[0][1]);
      }

      if(!$this->_done) {
        $feed = substr($this->_buff, $m[0][1]+strlen($m[0][0]));
      }

      $this->_buff = '';
    }

    else if($overlap = $this->_strOverlapEnd(
      "\n".substr($this->_buff, 0-($this->_bndl+1)),
      "\n".$this->_bndr
    )) {
      $ovrp = 0 - strlen($overlap) - 1;
      $data = substr($this->_buff, 0, $ovrp);

      if($data) {
        $this->_buff = substr($this->_buff, $ovrp);
      }
    }

    if($data) {
      if(!$this->_cntr) {
        $this->emit('preamble', [$data]);
      } else {
        $this->_mbff->feed($data);
      }
    }

    if($bndr) {
      $this->_cntr++;
      $this->_mbff->reset();

      if($this->_done) {
        $this->emit('done');
        $this->close();
      }
    }

    if($feed) {
      $this->feed($feed);
    }
  }

  public function close() {
    $this->_mbff->removeAllListeners();
    $this->emit('close');
    $this->removeAllListeners();
  }

  private function _strOverlapEnd(string $strA, string $strB) {
    if(!$strA || !$strB) return '';

    for($i=strlen($strB); $i>0; $i--) {
      if(($overlap=substr($strA, 0-$i)) == substr($strB, 0, $i)) {
        return $overlap;
      }
    }

    return '';
  }
}
