<?php

declare(strict_types=1); // @codeCoverageIgnore

namespace Recoil\React;

use Eloquent\Phony\Phony;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Recoil\Exception\TimeoutException;
use Recoil\Kernel\Api;
use Recoil\Kernel\Strand;
use Recoil\Kernel\SystemStrand;
use Throwable;

if (!interface_exists('React\EventLoop\TimerInterface') && interface_exists('React\EventLoop\Timer\TimerInterface')) {
    class_alias('React\EventLoop\Timer\TimerInterface', 'React\EventLoop\TimerInterface');
}

describe(StrandTimeout::class, function () {
    beforeEach(function () {
        $this->api = Phony::mock(Api::class);
        $this->timer = Phony::mock(TimerInterface::class);
        $this->loop = Phony::mock(LoopInterface::class);
        $this->loop->addTimer->returns($this->timer->get());
        $this->strand = Phony::mock(SystemStrand::class);

        $this->substrand = Phony::mock(SystemStrand::class);
        $this->substrand->id->returns(1);

        $this->subject = new StrandTimeout(
            $this->loop->get(),
            20.5,
            $this->substrand->get()
        );

        $this->subject->await(
            $this->strand->get(),
            $this->api->get()
        );
    });

    describe('->await()', function () {
        it('attaches a timer with the correct timeout', function () {
            $this->loop->addTimer->calledWith(
                20.5,
                [$this->subject, 'timeout']
            );
        });

        it('resumes the strand when the substrand is successful', function () {
            $this->strand->setTerminator->calledWith([$this->subject, 'cancel']);
            $this->substrand->setPrimaryListener->calledWith($this->subject);

            $this->strand->send->never()->called();
            $this->strand->throw->never()->called();

            $this->subject->send('<ok>', $this->substrand->get());

            $this->loop->cancelTimer->called();
            $this->strand->send->calledWith('<ok>');
        });

        it('resumes the strand with an exception when the substrand fails', function () {
            $exception = Phony::mock(Throwable::class);
            $this->subject->throw($exception->get(), $this->substrand->get());

            $this->loop->cancelTimer->called();
            $this->strand->throw->calledWith($exception);
        });

        it('resumes the strand with an exception if substrand times out', function () {
            $this->subject->timeout();

            $this->strand->throw->calledWith(
                TimeoutException::create(20.5)
            );
        });
    });

    describe('->cancel()', function () {
        it('terminates the substrand', function () {
            $this->subject->cancel();

            Phony::inOrder(
                $this->substrand->clearPrimaryListener->called(),
                $this->substrand->terminate->called()
            );
        });

        it('doesn\'t terminate the substrand if it has exited', function () {
            $this->subject->send('<ok>', $this->substrand->get());
            $this->subject->cancel();

            $this->substrand->setPrimaryListener->never()->calledWith(null);
            $this->substrand->terminate->never()->called();
        });
    });
});
