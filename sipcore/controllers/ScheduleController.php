<?php

class ScheduleController extends Controller {

    /**
     * @var string the default layout for the views. Defaults to '//layouts/column2', meaning
     * using two-column layout. See 'protected/views/layouts/column2.php'.
     */
    public $layout = '//layouts/ajax';

    /**
     * @return array action filters
     */
    public function filters() {
        return array(
            'editSchedule',
        );
    }

    /**
     * The filter.
     * @return boolean Whether the action should continue
     */
    public function filterEditSchedule($c) {
        if (Yii::app()->user->checkAccess('admin') ||
                (isset($_GET['id']) && Yii::app()->user->checkAccess('formteacher:' . intval($_GET['id']))) ||
                (isset($_POST['class']) && Yii::app()->user->checkAccess('formteacher:' . intval($_POST['class'])))) {
            $c->run();
            return true;
        }
        throw new CHttpException(403, 'Nu aveți acces la această clasă.');
        return false;
    }

    /**
     * Deletes a particular model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id the ID of the model to be deleted
     */
    public function actionDelete($weekday, $class, $hour) {
        if (Yii::app()->request->isPostRequest) {
            // we only allow deletion via POST request
            $this->loadModel($weekday, $class, $hour)->delete();

            // if AJAX request (triggered by deletion via admin grid view), we should not redirect the browser
            if (!isset($_GET['ajax']))
                $this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('admin'));
        } else
            throw new CHttpException(400, 'Cerere invalidă. Vă rugam să nu mai repetați această cerere.');
    }

    public function actionSaveAll() {
        if (!isset($_POST['newHour'], $_POST['class']))
            throw new CHttpException(400, 'Cerere invalidă. Vă rugăm să nu mai repetați această cerere.');
        $newHour = $_POST['newHour'];
        $class = $_POST['class'];
        if (!Classes::model()->exists('id=:class', array(':class' => $class)))
            throw new CHttpException(404, 'Clasa nu există.');

        if (Schedule::saveClassSchedule($newHour, $class))
            Yii::app()->user->setFlash('schedule', '2');
        else
            Yii::app()->user->setFlash('schedule', '3');
        $this->redirect(array('classes/view', 'id' => $class));
        Yii::app()->end();
    }

    /**
     * Returns the data model based on the primary key given in the GET variable.
     * If the data model is not found, an HTTP exception will be raised.
     * @param integer the ID of the model to be loaded
     */
    public function loadModel($weekday, $class, $hour) {
        $model = Schedule::model()->findByPk(array('weekday' => (int) $weekday, 'class' => (int) $class, 'hour' => (int) $hour));
        if ($model === null)
            throw new CHttpException(404, 'The requested page does not exist.');
        return $model;
    }

}
