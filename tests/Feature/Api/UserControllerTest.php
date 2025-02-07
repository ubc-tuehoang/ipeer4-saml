<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\AssertableJson;

use Symfony\Component\HttpFoundation\Response as Status;

use Laravel\Sanctum\Sanctum;

use App\Models\User;

use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $url = '/api/user';
    private int $perPage = 15;

    public function test_deny_access_to_guest_users(): void
    {
        // create a user so there's at least one in the database
        $user = User::factory()->create();
        $urlForUser = $this->url .'/'. $user->id;
        // GET all
        $resp = $this->getJson($this->url);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
        // GET single user
        $resp = $this->getJson($urlForUser);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
        // POST
        $params = ['username' => $user->username . '1',
                   'password' => $user->username . '1'];
        $resp = $this->postJson($this->url, $params);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
        // PUT / PATCH
        $params = ['name' => $user->name . '1'];
        $resp = $this->putJson($urlForUser, $params);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
        $resp = $this->patchJson($urlForUser, $params);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
        // DELETE
        $resp = $this->deleteJson($urlForUser);
        $resp->assertStatus(Status::HTTP_UNAUTHORIZED);
    }

    public function test_get_users()
    {
        // create a user so there's at least one in the database
        $user = User::factory()->create();
        // login via Sanctum
        Sanctum::actingAs($user, ['*']);

        // GET list of users
        $resp = $this->getJson($this->url);
        //$resp->dump();
        $resp->assertStatus(Status::HTTP_OK);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('total', 1)
                 ->where('per_page', $this->perPage)
                 ->hasAll([
                     'first_page_url',
                     'prev_page_url',
                     'next_page_url',
                     'last_page_url'
                 ])
                 ->has('data', 1, fn (AssertableJson $json) =>
                 $json->where('id', $user->id)
                      ->where('username', $user->username)
                      ->where('name', $user->name)
                      ->where('email', $user->email)
                      ->missing('password')
                      ->etc()
                 )
                 ->etc()
        );

        // create a second user
        $user2 = User::factory()->create();
        $urlForUser2 = $this->url .'/'. $user2->id;
        // GET single user
        $resp = $this->getJson($urlForUser2);
        $resp->assertStatus(Status::HTTP_OK);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('id', $user2->id)
                 ->where('username', $user2->username)
                 ->where('name', $user2->name)
                 ->where('email', $user2->email)
                 ->missing('password')
                 ->etc()
        );
    }

    public function test_get_users_paginated()
    {
        // create enough users to trigger the pagination, default per page
        // limit in laravel is 15
        $totalUsers = $this->perPage + 1;
        $users = User::factory()->count($totalUsers)->create();
        // login via Sanctum
        Sanctum::actingAs($users[0], ['*']);

        // GET the first page of users
        $resp = $this->getJson($this->url);
        $resp->assertStatus(Status::HTTP_OK);
        //$resp->dump();
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('total', $totalUsers)
                 ->where('per_page', $this->perPage)
                 ->has('data', $this->perPage, fn (AssertableJson $json) =>
                 $json->where('id', $users[0]->id)
                      ->where('username', $users[0]->username)
                      ->where('name', $users[0]->name)
                      ->where('email', $users[0]->email)
                      ->missing('password')
                      ->etc()
                 )
                 ->etc()
        );
        $nextPageUrl = $resp['next_page_url'];

        // get the next page of users, which should only have 1
        $resp = $this->getJson($nextPageUrl);
        $resp->assertStatus(Status::HTTP_OK);
        //$resp->dump();
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('total', $totalUsers)
                 ->where('per_page', $this->perPage)
                 ->has('data', 1, fn (AssertableJson $json) =>
                 $json->where('id', $users[$totalUsers-1]->id)
                      ->where('username', $users[$totalUsers-1]->username)
                      ->where('name', $users[$totalUsers-1]->name)
                      ->where('email', $users[$totalUsers-1]->email)
                      ->missing('password')
                      ->etc()
                 )
                 ->etc()
        );

    }

    /**
     * Test that we can sort by different columns when getting users
     */
    public function test_get_users_with_sorting()
    {
        // create enough users to trigger the pagination, default per page
        // limit in laravel is 15
        $totalUsers = $this->perPage + 1;
        $users = User::factory()->count($totalUsers)->create();
        // login via Sanctum
        Sanctum::actingAs($users[0], ['*']);

        $sortableFields = ['username', 'name', 'email', 'created_at',
                           'updated_at'];
        foreach ($sortableFields as $sortableField) {
            //fwrite(STDERR, "\n--- $sortableField ---\n");
            // sortBy leaves the old index keys in place, we need to use values()
            // to generate a new consecutively numbered index
            $users = $users->sortBy($sortableField)->values();

            // GET the first page of users
            $resp = $this->getJson($this->url . "?sort_by=$sortableField");
            $resp->assertStatus(Status::HTTP_OK);
            //$resp->dump();
            $resp->assertJson(fn (AssertableJson $json) =>
                $json->where('total', $totalUsers)
                     ->where('per_page', $this->perPage)
                     ->has('data', $this->perPage, fn (AssertableJson $json) =>
                     $json->where('id', $users[0]->id)
                          ->where('username', $users[0]->username)
                          ->where('name', $users[0]->name)
                          ->where('email', $users[0]->email)
                          ->missing('password')
                          ->etc()
                     )
                     ->etc()
            );
            $nextPageUrl = $resp['next_page_url'];
            // get the next page of users, which should only have 1
            $resp = $this->getJson($nextPageUrl);
            $resp->assertStatus(Status::HTTP_OK);
            //$resp->dump();
            $resp->assertJson(fn (AssertableJson $json) =>
                $json->where('total', $totalUsers)
                     ->where('per_page', $this->perPage)
                     ->has('data', 1, fn (AssertableJson $json) =>
                     $json->where('id', $users[$totalUsers-1]->id)
                          ->where('username', $users[$totalUsers-1]->username)
                          ->where('name', $users[$totalUsers-1]->name)
                          ->where('email', $users[$totalUsers-1]->email)
                          ->missing('password')
                          ->etc()
                     )
                     ->etc()
            );
        }
    }

    /**
     * By default, we sort by ascending, this tests if we can sort by descending 
     */
    public function test_get_user_with_sorting_descending()
    {
        // create enough users to trigger the pagination, default per page
        // limit in laravel is 15
        $totalUsers = $this->perPage + 1;
        $users = User::factory()->count($totalUsers)->create();
        // login via Sanctum
        Sanctum::actingAs($users[0], ['*']);

        $sortableField = 'username';
        // this time, we want descending order
        $users = $users->sortByDesc($sortableField)->values();

        // GET the first page of users
        $resp = $this->getJson($this->url .
                               "?sort_by=$sortableField&descending=true");
        $resp->assertStatus(Status::HTTP_OK);
        //$resp->dump();
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('total', $totalUsers)
                 ->where('per_page', $this->perPage)
                 ->has('data', $this->perPage, fn (AssertableJson $json) =>
                 $json->where('id', $users[0]->id)
                      ->where('username', $users[0]->username)
                      ->where('name', $users[0]->name)
                      ->where('email', $users[0]->email)
                      ->missing('password')
                      ->etc()
                 )
                 ->etc()
        );
        $nextPageUrl = $resp['next_page_url'];
        // get the next page of users, which should only have 1
        $resp = $this->getJson($nextPageUrl);
        $resp->assertStatus(Status::HTTP_OK);
        //$resp->dump();
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->where('total', $totalUsers)
                 ->where('per_page', $this->perPage)
                 ->has('data', 1, fn (AssertableJson $json) =>
                 $json->where('id', $users[$totalUsers-1]->id)
                      ->where('username', $users[$totalUsers-1]->username)
                      ->where('name', $users[$totalUsers-1]->name)
                      ->where('email', $users[$totalUsers-1]->email)
                      ->missing('password')
                      ->etc()
                 )
                 ->etc()
        );
    }

    public function test_create_user()
    {
        // create a user so there's at least one in the database
        $user = User::factory()->create();
        // login via Sanctum
        Sanctum::actingAs($user, ['*']);
        // this user is just generated and NOT stored in the database
        $user2 = User::factory()->make();
        // POST without optional params
        $params = ['username' => $user2->username,
                   'password' => $user2->username];
        $resp = $this->postJson($this->url, $params);
        $resp->assertStatus(Status::HTTP_CREATED);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->has('id')
                 ->where('username', $user2->username)
                 ->missing('password')
                 ->etc()
        );
        // this user is just generated and NOT stored in the database
        $user3 = User::factory()->make();
        // POST with optional params
        $params = ['username' => $user3->username,
                   'password' => $user3->username,
                   'name' => $user3->name,
                   'email' => $user3->email,
        ];
        $resp = $this->postJson($this->url, $params);
        $resp->assertStatus(Status::HTTP_CREATED);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->has('id')
                 ->where('username', $user3->username)
                 ->where('name', $user3->name)
                 ->where('email', $user3->email)
                 ->missing('password')
                 ->etc()
        );
    }

    public function test_update_user()
    {
        // create a user so there's at least one in the database
        $user = User::factory()->create();
        $urlForUser = $this->url .'/'. $user->id;
        // login via Sanctum
        Sanctum::actingAs($user, ['*']);
        // check PUT / PATCH both work
        $expectedName = $user->name . 'EDIT';
        $params = ['name' => $expectedName];
        $resp = $this->putJson($urlForUser, $params);
        $resp->assertStatus(Status::HTTP_OK);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->has('id')
                 ->where('username', $user->username)
                 ->where('name', $expectedName)
                 ->missing('password')
                 ->etc()
        );
        $expectedName = $user->name . 'ANOTHEREDIT';
        $params = ['name' => $expectedName];
        $resp = $this->patchJson($urlForUser, $params);
        $resp->assertStatus(Status::HTTP_OK);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->has('id')
                 ->where('username', $user->username)
                 ->where('name', $expectedName)
                 ->missing('password')
                 ->etc()
        );
        // check that all params can be changed
        $params = [
            'username' => $user->username . '1',
            'name' => $user->name . '1',
            'password' => $user->username . '1',
            'email' => '1' . $user->email,
        ];
        $resp = $this->putJson($urlForUser, $params);
        $resp->assertStatus(Status::HTTP_OK);
        $resp->assertJson(fn (AssertableJson $json) =>
            $json->has('id')
                 ->where('username', $user->username . '1')
                 ->where('name', $user->name . '1')
                 ->where('email', '1' . $user->email)
                 ->missing('password')
                 ->etc()
        );
        // TODO: check that password is changed & that only logged in user can
        // change their own password
    }

    public function test_delete_user()
    {
        // create a user so there's at least one in the database
        $user = User::factory()->create();
        // login via Sanctum
        Sanctum::actingAs($user, ['*']);
        // create a user to delete
        $user2 = User::factory()->create();
        $urlForUser = $this->url .'/'. $user2->id;
        // delete the user
        $resp = $this->deleteJson($urlForUser);
        $resp->assertStatus(Status::HTTP_OK);
        $this->assertDatabaseMissing('users',
                                    ['id' => $user2->id]);
    }

}
