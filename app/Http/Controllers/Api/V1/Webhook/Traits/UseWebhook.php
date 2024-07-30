<?php

namespace App\Http\Controllers\Api\V1\Webhook\Traits;

use App\Enums\TransactionName;
use App\Enums\TransactionStatus;
use App\Enums\WagerStatus;
use App\Http\Requests\Slot\SlotWebhookRequest;
use App\Models\Admin\GameType;
use App\Models\Admin\GameTypeProduct;
use App\Models\Admin\Product;
use App\Models\SeamlessEvent;
use App\Models\SeamlessTransaction;
use App\Models\User;
use App\Models\Wager;
use App\Services\Slot\Dto\RequestTransaction;
use App\Services\WalletService;
use Exception;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Log;

trait UseWebhook
{
    public function createEvent(
        SlotWebhookRequest $request,
    ): SeamlessEvent {
        return SeamlessEvent::create([
            'user_id' => $request->getMember()->id,
            'message_id' => $request->getMessageID(),
            'product_id' => $request->getProductID(),
            'request_time' => $request->getRequestTime(),
            'raw_data' => $request->all(),
        ]);
    }

    /**
     * @param  array<int,RequestTransaction>  $requestTransactions
     * @return array<int, SeamlessTransaction>
     *
     * @throws MassAssignmentException
     */
    public function createWagerTransactions(
        $requestTransactions,
        SeamlessEvent $event,
        bool $refund = false
    ) {
        $seamless_transactions = [];

        foreach ($requestTransactions as $requestTransaction) {
            $wager = Wager::firstOrCreate(
                ['seamless_wager_id' => $requestTransaction->WagerID],
                [
                    'user_id' => $event->user->id,
                    'seamless_wager_id' => $requestTransaction->WagerID,
                ]
            );

            if ($refund) {
                $wager->update([
                    'status' => WagerStatus::Refund,
                ]);
            } elseif (! $wager->wasRecentlyCreated) {
                $wager->update([
                    'status' => $requestTransaction->TransactionAmount > 0 ? WagerStatus::Win : WagerStatus::Lose,
                ]);
            }

            $game_type = GameType::where('code', $requestTransaction->GameType)->first();

            if (! $game_type) {
                throw new Exception("Game type not found for {$requestTransaction->GameType}");
            }
            $product = Product::where('code', $requestTransaction->ProductID)->first();

            if (! $product) {
                throw new Exception("Product not found for {$requestTransaction->ProductID}");
            }

            $game_type_product = GameTypeProduct::where('game_type_id', $game_type->id)
                ->where('product_id', $product->id)
                ->first();

            $rate = $game_type_product->rate;

            //Log::info("Rate is: {$rate}");

            // Debugging statements
            //logger()->info('GameType:', ['GameType' => $game_type]);
            //logger()->info('Product:', ['Product' => $product]);
            //logger()->info('GameTypeProduct:', ['GameTypeProduct' => $game_type_product]);

            $seamless_transactions[] = $event->transactions()->create([
                'user_id' => $event->user_id,
                'wager_id' => $wager->id,
                'game_type_id' => $game_type->id,
                'product_id' => $product->id,
                'seamless_transaction_id' => $requestTransaction->TransactionID,
                'rate' => $rate,
                'transaction_amount' => $requestTransaction->TransactionAmount,
                'bet_amount' => $requestTransaction->BetAmount,
                'valid_amount' => $requestTransaction->ValidBetAmount,
                'status' => $requestTransaction->Status,
            ]);
        }

        return $seamless_transactions;
    }

    public function processTransfer(User $from, User $to, TransactionName $transactionName, float $amount, int $rate, array $meta)
    {
        // Log the parameters
        // Log::info("Process transfer called", [
        //     'from' => $from,
        //     'to' => $to,
        //     'transactionName' => $transactionName,
        //     'amount' => $amount,
        //     'rate' => $rate,
        //     'meta' => $meta,
        // ]);

        //  Log::info("Calling WalletService transfer", [
        //     'from' => $from,
        //     'to' => $to,
        //     'amount' => $amount,
        //     'transactionName' => $transactionName,
        //     'meta' => $meta,
        // ]);
        //  Log::info("WalletService transfer called", [
        //     'from' => $from,
        //     'to' => $to,
        //     'amount' => $amount,
        //     'transactionName' => gettype($transactionName), // Log the type
        //     'transactionName_value' => $transactionName,   // Log the value
        //     'meta' => $meta,
        // ]);
        // TODO: ask: what if operator doesn't want to pay bonus
        app(WalletService::class)
            ->transfer(
                $from,
                $to,
                abs($amount),
                $transactionName,
                $meta
            );
    }
}
