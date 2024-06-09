<?php
namespace Trader\Strategy;

use Trader\Model\Deal;

/**
 * A strategy is a set of smaller checks of market conditions to trigger orders.
 * Pipeline is a set of filters of market analysis that returns an Order if all filters are met.
 * First filter of pipeline create an order, the rest can modify or replace it.
 */
class Pipeline
{
    /**
     * @var callable[]
     */
    private array $stages = [];
    private InterruptibleProcessor $processor;

    public function __construct(
        #protected Account $account,
    )
    {
        // any stage returning false will interrupt the pipeline
        $this->processor = new InterruptibleProcessor(fn($payload) => $payload !== false);
    }

    public function __invoke(Deal $deal)
    {
        return $this->processor->process($deal, ...$this->stages);
    }

    public function pipe(callable $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }
}
