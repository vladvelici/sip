<?php

/**
 * This is the model class for table "sms".
 *
 * The followings are the available columns in table 'sms':
 * @property integer $id
 * @property string $added
 * @property integer $average_id
 * @property string $message
 * @property integer $sent
 * @property integer $status
 * The followings are the available model relations:
 * @property Averages $average
 */
class Sms extends CActiveRecord
{
        // SMS statuses
        const STATUS_DRAFT = 0;
        const STATUS_SENDING = 1;
        const STATUS_SENT = 2;
        const STATUS_ERROR = 3;
        const STATUS_TOSEND = 4;
        
        
        const ADD_AVERAGE_LOW = 2; // corigenta sau scadere cu la un subiect anume
        const ADD_ABSENCES_WARNING = 3; // 8 sau mai multe absente nemotivate
        const ADD_ABSENCES_AUTHORIZED = 4; // motivare de interval de absențe
        
        const SMS_DRAFT_TIME=259200; // 3 days
        const SMS_QUIET_TIME=604800; // 7 days
        
        const MAX_ABSENCES_ALLOWED=8; // 8 absente pt a trimite SMS
         /**
	 * Returns the static model of the specified AR class.
	 * @return Sms the static model class
	 */
	public static function model($className=__CLASS__)
	{
		return parent::model($className);
	}

	/**
	 * @return string the associated database table name
	 */
	public function tableName()
	{
		return 'sms';
	}

	/**
	 * @return array relational rules.
	 */
	public function relations()
	{
		// NOTE: you may need to adjust the relation name and the related
		// class name for the relations automatically generated below.
		return array(
			'rAverage' => array(self::BELONGS_TO, 'Averages', 'average_id'),
		);
	}
        
        public function rules () {
            return array (
                array('message','length','min'=>5,'max'=>254,'on'=>'manualSms'),
            );
        }
        
        /**
         * Returneaza un text pentru oameni in functie de $status
         * @param integer $status
         * @return string The $status for humans 
         */

        public static function textStatus($status) {
            if ($type === Sms::STATUS_DRAFT)
                return 'ciornă';
            elseif ($type===Sms::STATUS_SENDING)
                return 'trimis, așteptare răspuns';
            elseif ($type===SMS::STATUS_SENT)
                return 'trimis cu succes';
            elseif ($type===Sms::STATUS_ERROR)
                return 'eroare la trimitere';
            elseif ($type===Sms::STATUS_TOSEND)
                return 'de trimis';
            return '';
        }
        
	/**
	 * @return array customized attribute labels (name=>label)
	 */
	public function attributeLabels()
	{
		return array(
			'id' => 'ID',
			'added' => 'Adăugat',
			'average_id' => 'Medie',
			'message' => 'Mesaj',
		);
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 * @return CActiveDataProvider the data provider that can return the models based
         * on the search/filter conditions.
	 */
	public function search()
	{
		// Warning: Please modify the following code to remove attributes that
		// should not be searched.

		$criteria=new CDbCriteria;

		$criteria->compare('id',$this->id);
		$criteria->compare('added',$this->added,true);
		$criteria->compare('average_id',$this->average_id);
		$criteria->compare('message',$this->message,true);

		return new CActiveDataProvider(get_class($this), array(
			'criteria'=>$criteria,
		));
	}
        /**
         * @todo JSON encoding and decoding to save DRAFTS,
         * create SMS_render method
         * create cron job console script
         * rewrite the following code
         */
        // SMS draft calculations:
        
        /**
         * @param int student id
         * @return mixed Sms instance with the draft or null if failed 
         */
        public function getCurrentDraft($student) {
            return $this->find(array(
                'condition'=>'status=:status AND student=:student',
                'params'=>array(
                    ':status'=>self::STATUS_DRAFT,
                    ':student'=>$student,
                ),
            ));
        }
        
        public function saveDraft($student, $reason, $params=array()) {
            // check if reason has the necessary params
            if ($reason===self::ADD_AVERAGE_LOW && !isset($params['subject']))
                return false;
            if ($reason===self::ADD_ABSENCES_AUTHORIZED && !isset($params['start'], $params['end']))
                return false; 
            //get the current SMS draft
            $model = $this->getCurrentDraft($student);
            if ($model===null) {
                // if no draft, simply save the reason and params
                $model = new Sms;
                if ($reason===self::ADD_AVERAGE_LOW) {
                    $model->message=array($reason=>array($params['subject']=>true));
                } elseif ($reason===self::ADD_ABSENCES_WARNING) {
                    $model->message=array($reason=>true);
                } elseif ($reason===self::ADD_ABSENCES_AUTHORIZED) {
                    $model->message=array($reason=>array($params['start']=>$params['end']));
                }
                $model->student=$student;
                $model->status=self::STATUS_DRAFT;
                $model->added=time();
                return $model->save();
            } else {
                return $model->updateDraft($reason,$params);
            }
        }
       
        public function updateDraft($reason, $params) {
             if (isset($this->message[$reason])) {
                if ($reason===self::ADD_ABSENCES_WARNING && isset($this->message[self::ADD_ABSENCES_WARNING]))
                    return true;
                if ($reason===self::ADD_AVERAGE_LOW && isset($this->message[$reason][$params['subject']]))
                    return true;
                if ($reason===self::ADD_ABSENCES_AUTHORIZED && isset($this->message[$reason][$params['start']]) &&
                        $this->message[$reason][$params['start']] >= $params['end']) {
                    return true;
                }
             }
             $newMsg = $this->message;
             if ($reason===self::ADD_AVERAGE_LOW) {
                 $newMsg[$reason][$params['subject']]=true;
             } elseif ($reason===self::ADD_ABSENCES_WARNING) {
                 $newMsg[$reason]=true;
             } elseif ($reason===self::ADD_ABSENCES_AUTHORIZED) {
                 $newMsg[$reason][$params['start']]=$params['end'];
             }
             $this->message=$newMsg;
             return (bool) $this->update('message');
        }

        public function checkToSend ($limit=5) {
            $toSend = $this->findAll(array(
                'condition'=>'status=:status',
                'limit'=>$limit,
                'order'=>'added ASC',
                'params'=>array(
                    ':status'=>self::STATUS_TOSEND,
                ),
            ));
            $i = 0;
            foreach ($toSend as $sms) {
                $sms->status=self::STATUS_SENDING;
                $sms->send(); $i++;
            }
            return $i;
        }
        
        public function checkDrafts($limit=5) {
            $t = time();
            $drafts=$this->findAll(array(
                //'condition'=>'status=:status AND added<:added',
		'condition'=>'status=:status',
                'limit'=>$limit,
                'params'=>array(
                    ':status'=>self::STATUS_DRAFT,
                   // ':added'=>$t-self::SMS_DRAFT_TIME,
                ),
            ));
            $trimise=0; $esuate=0;
            foreach ($drafts as $draft)
            {
                //var_dump($draft);
                $lastSent = $this->find(array(
                    'condition'=>'(status=:status1 OR status=:status2) AND added=:student',
                    'order'=>'status ASC, sent DESC',
                    'params'=>array(
                        ':status1'=>self::STATUS_SENT,
                        ':status2'=>self::STATUS_SENDING,
                        ':student'=>$draft->student,
                    ),
                ));
                if ($lastSent===null ||
                    $lastSent->status==self::STATUS_SENT && $t-$lastSent->sent>self::SMS_QUIET_TIME)
                {
                    $absente = '';
                    $msgmedie = '';
                    $motivari = '';
                    if (isset($draft->message[self::ADD_ABSENCES_AUTHORIZED])) {
                        foreach ($draft->message[self::ADD_ABSENCES_AUTHORIZED] as $start => $end) {
                            if (!$motivari)
                                $motivari = 'Au fost motivate absentele din ';
                            else
                                $motivari .= ', ';
                            $motivari.=date('d F Y',$start).' - '.date('d F Y',$end);
                        }
                    }
                    if ($motivari) $motivari .= '.';
                    if (isset($draft->message[self::ADD_ABSENCES_WARNING]) && $draft->message[self::ADD_ABSENCES_WARNING]) {
                        $absente = Absences::model()->countByAttributes(array('student'=>$draft->student,'authorized'=>Absences::STATUS_UNAUTH));
                        if ($absente >= self::MAX_ABSENCES_ALLOWED) {
                            $absente = 'Absente nemotivate: '.$absente.'.';
                        }
                    }
                   if (isset($draft->message[self::ADD_AVERAGE_LOW])) {
                        $cu3pct=array(); $sub4 = array();
                        foreach ($draft->message[self::ADD_AVERAGE_LOW] as $subject=>$k) {
                            $medie = Averages::model()->find(array(
                                'order'=>'added DESC'
                            ));
                            if ($medie!==null) {
                                if ($medie->average>4.5) {
                                    $medie_veche =Averages::model()->find(array(
                                        'condition'=>'added!=:added'.(isset($lastSent->added) ? ' AND added<'.$lastSent->added : ''),
                                        'order'=>'added DESC',
                                        'params'=>array(':added'=>$medie->added),
                                    ));
                                    if ($medie_veche!==null && $medie_veche->average-$medie->average>=3) {
                                        $cu3pct[]= Subject::getSubjectName($subject);
                                    }
                                } else
                                    $sub4[] = Subject::getSubjectName($subject);
                            }
                        }
                        if (!empty($sub4))
                            $msgmedie .= "Situatie de corigenta la: ".  implode(', ', $sub4).'. ';
                        if (!empty($cu3pct))
                            $msgmedie .= "Media a scazut cu mimin 3 puncte la: ".implode(', ',$cu3pct).'.';
                    }
                    $mesaj = $msgmedie.$motivari.$absente;
                    if ($mesaj) {
                        $draft->message=$mesaj;
                        $draft->status=self::STATUS_SENDING;
                        if ($draft->send())
                            $trimise++;
                        else
                            $esuate++;
                    } else {
                        $draft->delete();
                    }
                }
                
            }
            echo $esuate." sms fails\n";
            return $trimise;
        }
        
/*        public function killRoChars($text) {
            $ro = array('ă','â','î','ș','ț','Ă','Â','Î','Ș','Ț');
            $n  = array('a','a','i','s','t','A','A','I','S','T');
            return str_replace($ro, $n, $text);
        }*/
        
        // sending message
        public function send() {
            if ($this->status!=self::STATUS_SENDING)
                    return false;
            
            if (!$this->to) {
                $student = Student::model()->with('rParent')->findByPk($this->student);
                $paccount = Account::model()->findByAttributes(array('type'=>Account::TYPE_PARENT,'info'=>$student->rParent->id));
                $this->to = $paccount->phone;
            }
            if (!$this->save())
                    return false;
            
            //sending
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://api.clickatell.com/http/sendmsg");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_POST, 7);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 
                    'api_id=3294837&'.
                    'user=vlad.velici&'.
                    'password='.urlencode('BHMQUYkltv35').'&'.
                    'to='.$this->to.'&'.
                    'text='.urlencode($this->message).'&'.
                    'callback=2&cliMsgId='.$this->id);
            curl_setopt($ch, CURLOPT_CAINFO, Yii::app()->basePath.'sms_ssl/www.clickatell.com');
            $response = curl_exec($ch);
            curl_close($ch);
            //var_dump($response);
            return (bool) $response; return true;
        }
        
        // saving and loading
        protected function afterFind() {
            parent::afterFind();
            if ($this->status == self::STATUS_DRAFT) {
                $this->message = json_decode($this->message, true);
            }
            return true;
        }
        protected function beforeSave() {
            if (parent::beforeSave()) {
                if (is_array($this->message)) {
                    $this->message=json_encode($this->message);
                }
                if ($this->getScenario()=='manualSms') {
                    $purifier = new CHtmlPurifier();
                    $this->message = $purifier->purify($this->message);
                }
                return true;
            } else
                return false;
        }
}
