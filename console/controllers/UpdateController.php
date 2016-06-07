<?php

namespace console\controllers;

use yii;
use common\models\UpdateGiftsDBLog;
use common\components\exceptions\SimpleXMLException;

class UpdateController extends \yii\console\Controller
{
    public function actionStock()
    {
        $stockArr = [];
        try
        {
            //Закгрузка файла stock.xml
            yii::beginProfile('update_StockFilePrepare');
            $stockXML = new \SimpleXMLElement(
                file_get_contents(yii::$app->params['xmlUploadPath']['current'] . '/stock.xml')
            );
            yii::endProfile('update_StockFilePrepare');

            if($stockXML === false)
            {
                throw new SimpleXMLException('File stock.xml was not processed.');
            }

            //Формирование массива с количеством товаров и их ценами
            yii::beginProfile('update_StockFileAnalyze');
            $stockArr = $this->makeArrFromStockTree($stockXML);
            yii::endProfile('update_StockFileAnalyze');

            $updateProductResult = 0;
            $updateSlaveProductResult = 0;

            if (count($stockArr) > 0)
            {
                $productResults = Yii::$app->db->createCommand('
                    SELECT [[id]], [[code]] FROM {{%product_tmp}}
                ')->queryAll();
                foreach ($productResults as $row)
                {
                    if (isset($stockArr[$row['id']]))
                    {
                        $updateProductResult += (int) Yii::$app->db->createCommand()->update(
                            '{{%product_tmp}}',
                            [
                                'amount' => (int)$stockArr[$row['id']]['amount'],
                                'free' => (int)$stockArr[$row['id']]['free'],
                                'inwayamount' => (int)$stockArr[$row['id']]['inwayamount'],
                                'inwayfree' => (int)$stockArr[$row['id']]['inwayfree'],
                                'enduserprice' => (float)$stockArr[$row['id']]['enduserprice'],
                            ],
                            [
                                'id' => $row['id'],
                                'code' => $row['code'],
                            ]
                        )->execute();
                    }
                }

                $slaveProductResults = Yii::$app->db->createCommand('
                    SELECT [[id]], [[code]] FROM {{%slave_product_tmp}}
                ')->queryAll();
                foreach ($slaveProductResults as $row)
                {
                    if (isset($stockArr[$row['id']]))
                    {
                        $updateSlaveProductResult += (int) Yii::$app->db->createCommand()->update(
                            '{{%slave_product_tmp}}',
                            [
                                'amount' => (int)$stockArr[$row['id']]['amount'],
                                'free' => (int)$stockArr[$row['id']]['free'],
                                'inwayamount' => (int)$stockArr[$row['id']]['inwayamount'],
                                'inwayfree' => (int)$stockArr[$row['id']]['inwayfree'],
                                'enduserprice' => (float)$stockArr[$row['id']]['enduserprice'],
                            ],
                            [
                                'id' => $row['id'],
                                'code' => $row['code'],
                            ]
                        )->execute();
                    }
                }

                Yii::$app->updateGiftsDBLogger->info(UpdateGiftsDBLog::ACTION_UPDATE, UpdateGiftsDBLog::ITEM_PRODUCT, 'Обновлены цены и/или остатки у ' . $updateProductResult . ' товаров во временной таблице.');
                Yii::$app->updateGiftsDBLogger->info(UpdateGiftsDBLog::ACTION_UPDATE, UpdateGiftsDBLog::ITEM_SLAVE_PRODUCT, 'Обновлены остатки у ' . $updateSlaveProductResult . ' подчиненых товаров во временной таблице.');

//                Yii::$app->db->createCommand('CALL gifts_update_stock()')->execute();
                $updateProductResult = (int) Yii::$app->db->createCommand('
                    UPDATE dpd_product as p, dpd_product_tmp as pt
                    SET
                        p.amount = pt.amount,
                        p.free = pt.free,
                        p.inwayamount = pt.inwayamount,
                        p.inwayfree = pt.inwayfree,
                        p.enduserprice = pt.enduserprice
                    WHERE
                        p.id = pt.id and p.code = pt.code
                ')->execute();

                $updateSlaveProductResult = (int) Yii::$app->db->createCommand('
                    UPDATE dpd_slave_product as sp, dpd_slave_product_tmp as spt
                    SET
                        sp.amount = spt.amount,
                        sp.free = spt.free,
                        sp.inwayamount = spt.inwayamount,
                        sp.inwayfree = spt.inwayfree,
                        sp.enduserprice = spt.enduserprice
                    WHERE
                        sp.id = spt.id and sp.code = spt.code
                ')->execute();

                Yii::$app->updateGiftsDBLogger->info(UpdateGiftsDBLog::ACTION_UPDATE, UpdateGiftsDBLog::ITEM_PRODUCT, 'Обновлены цены и/или остатки у ' . $updateProductResult . ' товаров.');
                Yii::$app->updateGiftsDBLogger->info(UpdateGiftsDBLog::ACTION_UPDATE, UpdateGiftsDBLog::ITEM_SLAVE_PRODUCT, 'Обновлены остатки у ' . $updateSlaveProductResult . ' подчиненых товаров.');
            }
        }
        catch (SimpleXMLException $xmlE)
        {
            yii::endProfile('update_StockFilePrepare');
            yii::endProfile('update_StockFileAnalyze');
            Yii::$app->updateGiftsDBLogger->error(
                UpdateGiftsDBLog::ACTION_INSERT,
                UpdateGiftsDBLog::ITEM_STOCK,
                'Ошибка во время парсирования (разбора) файла stock.xml'
            );
            echo $xmlE->getMessage() . "\n";
        }
        catch (Exception $e)
        {
            yii::endProfile('update_StockFilePrepare');
            yii::endProfile('update_StockFileAnalyze');
            Yii::$app->updateGiftsDBLogger->error(UpdateGiftsDBLog::ACTION_INSERT, UpdateGiftsDBLog::ITEM_STOCK, $e->getMessage());
            echo $e->getMessage() . "\n";
        }

    }

    protected function makeArrFromStockTree(\SimpleXMLElement $tree)
    {
        $arr = [];
        foreach ($tree->stock as $stock)
        {
            $productId = (int) $stock->product_id;
            $arr[$productId] = [
                'product_id' => $productId,
                'code' => (string) $stock->code,
                'amount' => (int) $stock->amount,
                'free' => (int) $stock->free,
                'inwayamount' => (int) $stock->inwayamount,
                'inwayfree' => (int) $stock->inwayfree,
                'enduserprice' => (float) $stock->enduserprice,
            ];
        }

        return $arr;
    }
}
