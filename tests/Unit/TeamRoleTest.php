<?php

use App\Enums\TeamRole;

describe('TeamRole enum', function () {
    it('has correct values', function () {
        expect(TeamRole::OWNER->value)->toBe('owner');
        expect(TeamRole::MEMBER->value)->toBe('member');
    });

    it('returns all values', function () {
        $values = TeamRole::values();

        expect($values)->toBeArray();
        expect($values)->toContain('owner', 'member');
        expect(count($values))->toBe(2);
    });

    it('returns correct labels', function () {
        expect(TeamRole::OWNER->label())->toBe('Owner');
        expect(TeamRole::MEMBER->label())->toBe('Member');
    });

    it('correctly identifies owner role', function () {
        expect(TeamRole::OWNER->isOwner())->toBeTrue();
        expect(TeamRole::MEMBER->isOwner())->toBeFalse();
    });

    it('correctly identifies member role', function () {
        expect(TeamRole::MEMBER->isMember())->toBeTrue();
        expect(TeamRole::OWNER->isMember())->toBeFalse();
    });

    it('owner has all permissions', function () {
        expect(TeamRole::OWNER->can('update'))->toBeTrue();
        expect(TeamRole::OWNER->can('delete'))->toBeTrue();
        expect(TeamRole::OWNER->can('invite'))->toBeTrue();
        expect(TeamRole::OWNER->can('removeMember'))->toBeTrue();
        expect(TeamRole::OWNER->can('manageSettings'))->toBeTrue();
        expect(TeamRole::OWNER->can('view'))->toBeTrue();
        expect(TeamRole::OWNER->can('viewData'))->toBeTrue();
        expect(TeamRole::OWNER->can('editData'))->toBeTrue();
    });

    it('member has limited permissions', function () {
        expect(TeamRole::MEMBER->can('view'))->toBeTrue();
        expect(TeamRole::MEMBER->can('viewData'))->toBeTrue();
        expect(TeamRole::MEMBER->can('editData'))->toBeTrue();

        expect(TeamRole::MEMBER->can('update'))->toBeFalse();
        expect(TeamRole::MEMBER->can('delete'))->toBeFalse();
        expect(TeamRole::MEMBER->can('invite'))->toBeFalse();
        expect(TeamRole::MEMBER->can('removeMember'))->toBeFalse();
        expect(TeamRole::MEMBER->can('manageSettings'))->toBeFalse();
    });
});
