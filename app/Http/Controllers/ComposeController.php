<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Auth, Cache, Storage, URL;
use Carbon\Carbon;
use App\{
	Avatar,
	Hashtag,
	Like,
	Media,
	MediaTag,
	Notification,
	Profile,
	Place,
	Status,
	UserFilter
};
use App\Transformer\Api\{
	MediaTransformer,
	MediaDraftTransformer,
	StatusTransformer,
	StatusStatelessTransformer
};
use League\Fractal;
use App\Util\Media\Filter;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use App\Jobs\AvatarPipeline\AvatarOptimize;
use App\Jobs\ImageOptimizePipeline\ImageOptimize;
use App\Jobs\ImageOptimizePipeline\ImageThumbnail;
use App\Jobs\StatusPipeline\NewStatusPipeline;
use App\Jobs\VideoPipeline\{
	VideoOptimize,
	VideoPostProcess,
	VideoThumbnail
};
use App\Services\NotificationService;
use App\Services\MediaPathService;
use App\Services\MediaBlocklistService;
use App\Services\MediaStorageService;
use App\Services\MediaTagService;
use App\Services\ServiceService;
use Illuminate\Support\Str;
use App\Util\Lexer\Autolink;
use App\Util\Lexer\Extractor;

class ComposeController extends Controller
{
	protected $fractal;

	public function __construct()
	{
		$this->middleware('auth');
		$this->fractal = new Fractal\Manager();
		$this->fractal->setSerializer(new ArraySerializer());
	}

	public function show(Request $request)
	{
		return view('status.compose');
	}

	public function mediaUpload(Request $request)
	{
		abort_if(!$request->user(), 403);

		$this->validate($request, [
			'file.*' => function() {
				return [
					'required',
					'mimes:' . config('pixelfed.media_types'),
					'max:' . config('pixelfed.max_photo_size'),
				];
			},
			'filter_name' => 'nullable|string|max:24',
			'filter_class' => 'nullable|alpha_dash|max:24'
		]);

		$user = Auth::user();
		$profile = $user->profile;

		$limitKey = 'compose:rate-limit:media-upload:' . $user->id;
		$limitTtl = now()->addMinutes(15);
		$limitReached = Cache::remember($limitKey, $limitTtl, function() use($user) {
			$dailyLimit = Media::whereUserId($user->id)->where('created_at', '>', now()->subDays(1))->count();

			return $dailyLimit >= 250;
		});

		abort_if($limitReached == true, 429);

		if(config('pixelfed.enforce_account_limit') == true) {
			$size = Cache::remember($user->storageUsedKey(), now()->addDays(3), function() use($user) {
				return Media::whereUserId($user->id)->sum('size') / 1000;
			}); 
			$limit = (int) config('pixelfed.max_account_size');
			if ($size >= $limit) {
				abort(403, 'Account size limit reached.');
			}
		}

		$filterClass = in_array($request->input('filter_class'), Filter::classes()) ? $request->input('filter_class') : null;
		$filterName = in_array($request->input('filter_name'), Filter::names()) ? $request->input('filter_name') : null;

		$photo = $request->file('file');

		$mimes = explode(',', config('pixelfed.media_types'));

		abort_if(in_array($photo->getMimeType(), $mimes) == false, 400, 'Invalid media format');

		$storagePath = MediaPathService::get($user, 2);
		$path = $photo->store($storagePath);
		$hash = \hash_file('sha256', $photo);

		abort_if(MediaBlocklistService::exists($hash) == true, 451);

		$media = new Media();
		$media->status_id = null;
		$media->profile_id = $profile->id;
		$media->user_id = $user->id;
		$media->media_path = $path;
		$media->original_sha256 = $hash;
		$media->size = $photo->getSize();
		$media->mime = $photo->getMimeType();
		$media->filter_class = $filterClass;
		$media->filter_name = $filterName;
		$media->version = 3;
		$media->save();

		$preview_url = $media->url() . '?v=' . time();
		$url = $media->url() . '?v=' . time();
		
		switch ($media->mime) {
			case 'image/jpeg':
			case 'image/png':
			ImageOptimize::dispatch($media);
			break;

			case 'video/mp4':
			VideoThumbnail::dispatch($media);
			$preview_url = '/storage/no-preview.png';
			$url = '/storage/no-preview.png';
			break;

			default:
			break;
		}

		Cache::forget($limitKey);
		$resource = new Fractal\Resource\Item($media, new MediaTransformer());
		$res = $this->fractal->createData($resource)->toArray();
		$res['preview_url'] = $preview_url;
		$res['url'] = $url;
		return response()->json($res);
	}

	public function mediaUpdate(Request $request)
	{
		$this->validate($request, [
			'id' => 'required',
			'file' => function() {
				return [
					'required',
					'mimes:' . config('pixelfed.media_types'),
					'max:' . config('pixelfed.max_photo_size'),
				];
			},
		]);

		$user = Auth::user();

		$limitKey = 'compose:rate-limit:media-updates:' . $user->id;
		$limitTtl = now()->addMinutes(15);
		$limitReached = Cache::remember($limitKey, $limitTtl, function() use($user) {
			$dailyLimit = Media::whereUserId($user->id)->where('created_at', '>', now()->subDays(1))->count();

			return $dailyLimit >= 500;
		});

		abort_if($limitReached == true, 429);

		$photo = $request->file('file');
		$id = $request->input('id');

		$media = Media::whereUserId($user->id)
		->whereProfileId($user->profile_id)
		->whereNull('status_id')
		->findOrFail($id);

		$media->save();

		$fragments = explode('/', $media->media_path);
		$name = last($fragments);
		array_pop($fragments);
		$dir = implode('/', $fragments);
		$path = $photo->storeAs($dir, $name);
		$res = [
			'url' => $media->url() . '?v=' . time()
		];
		ImageOptimize::dispatch($media);
		Cache::forget($limitKey);
		return $res;
	}

	public function mediaDelete(Request $request)
	{
		abort_if(!$request->user(), 403);

		$this->validate($request, [
			'id' => 'required|integer|min:1|exists:media,id'
		]);

		$media = Media::whereNull('status_id')
		->whereUserId(Auth::id())
		->findOrFail($request->input('id'));

		MediaStorageService::delete($media, true);

		$media->forceDelete();

		return response()->json([
			'msg' => 'Successfully deleted',
			'code' => 200
		]);
	}

	public function searchTag(Request $request)
	{
		abort_if(!$request->user(), 403);

		$this->validate($request, [
			'q' => 'required|string|min:1|max:50'
		]);

		$q = $request->input('q');

		if(Str::of($q)->startsWith('@')) {
			if(strlen($q) < 3) {
				return [];
			}
			$q = mb_substr($q, 1);
		}

		$blocked = UserFilter::whereFilterableType('App\Profile')
			->whereFilterType('block')
			->whereFilterableId($request->user()->profile_id)
			->pluck('user_id');

		$blocked->push($request->user()->profile_id);

		$results = Profile::select('id','domain','username')
			->whereNotIn('id', $blocked)
			->whereNull('domain')
			->where('username','like','%'.$q.'%')
			->limit(15)
			->get()
			->map(function($r) {
				return [
					'id' => (string) $r->id,
					'name' => $r->username,
					'privacy' => true,
					'avatar' => $r->avatarUrl()
				];
		});

		return $results;
	}

    public function searchUntag(Request $request)
    {
        abort_if(!$request->user(), 403);

        $this->validate($request, [
            'status_id' => 'required',
            'profile_id' => 'required'
        ]);

        $user = $request->user();
        $status_id = $request->input('status_id');
        $profile_id = (int) $request->input('profile_id');

        abort_if((int) $user->profile_id !== $profile_id, 400);

        $tag = MediaTag::whereStatusId($status_id)
            ->whereProfileId($profile_id)
            ->first();

        if(!$tag) {
            return [];
        }
        Notification::whereItemType('App\MediaTag')
            ->whereItemId($tag->id)
            ->whereProfileId($profile_id)
            ->whereAction('tagged')
            ->delete();

        MediaTagService::untag($status_id, $profile_id);

        return [200];
    }

	public function searchLocation(Request $request)
	{
		abort_if(!Auth::check(), 403);
		$this->validate($request, [
			'q' => 'required|string|max:100'
		]);
		$q = filter_var($request->input('q'), FILTER_SANITIZE_STRING);
		$hash = hash('sha256', $q);
		$key = 'search:location:id:' . $hash;
		$places = Cache::remember($key, now()->addMinutes(15), function() use($q) {
			$q = '%' . $q . '%';
			return Place::where('name', 'like', $q)
			->take(80)
			->get()
			->map(function($r) {
				return [
					'id' => $r->id,
					'name' => $r->name,
					'country' => $r->country,
					'url'   => $r->url()
				];
			});
		});
		return $places;
	}

	public function searchMentionAutocomplete(Request $request)
	{
		abort_if(!$request->user(), 403);

		$this->validate($request, [
			'q' => 'required|string|min:2|max:50'
		]);

		$q = $request->input('q');

		if(Str::of($q)->startsWith('@')) {
			if(strlen($q) < 3) {
				return [];
			}
		}

		$blocked = UserFilter::whereFilterableType('App\Profile')
			->whereFilterType('block')
			->whereFilterableId($request->user()->profile_id)
			->pluck('user_id');

		$blocked->push($request->user()->profile_id);

		$results = Profile::select('id','domain','username')
			->whereNotIn('id', $blocked)
			->where('username','like','%'.$q.'%')
			->groupBy('domain')
			->limit(15)
			->get()
			->map(function($profile) {
				$username = $profile->domain ? substr($profile->username, 1) : $profile->username;
                return [
                    'key' => '@' . str_limit($username, 30),
                    'value' => $username,
                ];
		});

		return $results;
	}

	public function searchHashtagAutocomplete(Request $request)
	{
		abort_if(!$request->user(), 403);

		$this->validate($request, [
			'q' => 'required|string|min:2|max:50'
		]);

		$q = $request->input('q');

		$results = Hashtag::select('slug')
			->where('slug', 'like', '%'.$q.'%')
			->whereIsNsfw(false)
			->whereIsBanned(false)
			->limit(5)
			->get()
			->map(function($tag) {
				return [
					'key' => '#' . $tag->slug,
					'value' => $tag->slug
				];
		});

		return $results;
	}

	public function store(Request $request)
	{
		$this->validate($request, [
			'caption' => 'nullable|string|max:'.config('pixelfed.max_caption_length', 500),
			'media.*'   => 'required',
			'media.*.id' => 'required|integer|min:1',
			'media.*.filter_class' => 'nullable|alpha_dash|max:30',
			'media.*.license' => 'nullable|string|max:140',
			'media.*.alt' => 'nullable|string|max:140',
			'cw' => 'nullable|boolean',
			'visibility' => 'required|string|in:public,private,unlisted|min:2|max:10',
			'place' => 'nullable',
			'comments_disabled' => 'nullable',
			'tagged' => 'nullable',
			// 'optimize_media' => 'nullable'
		]);

		if(config('costar.enabled') == true) {
			$blockedKeywords = config('costar.keyword.block');
			if($blockedKeywords !== null && $request->caption) {
				$keywords = config('costar.keyword.block');
				foreach($keywords as $kw) {
					if(Str::contains($request->caption, $kw) == true) {
						abort(400, 'Invalid object');
					}
				}
			}
		}

		$user = Auth::user();
		$profile = $user->profile;

		$limitKey = 'compose:rate-limit:store:' . $user->id;
		$limitTtl = now()->addMinutes(15);
		$limitReached = Cache::remember($limitKey, $limitTtl, function() use($user) {
			$dailyLimit = Status::whereProfileId($user->profile_id)
				->whereNull('in_reply_to_id')
				->whereNull('reblog_of_id')
				->where('created_at', '>', now()->subDays(1))
				->count();

			return $dailyLimit >= 100;
		});

		abort_if($limitReached == true, 429);

		$visibility = $request->input('visibility');
		$medias = $request->input('media');
		$attachments = [];
		$status = new Status;
		$mimes = [];
		$place = $request->input('place');
		$cw = $request->input('cw');
		$tagged = $request->input('tagged');
		$optimize_media = (bool) $request->input('optimize_media');

		foreach($medias as $k => $media) {
			if($k + 1 > config('pixelfed.max_album_length')) {
				continue;
			}
			$m = Media::findOrFail($media['id']);
			if($m->profile_id !== $profile->id || $m->status_id) {
				abort(403, 'Invalid media id');
			}
			$m->filter_class = in_array($media['filter_class'], Filter::classes()) ? $media['filter_class'] : null;
			$m->license = $media['license'];
			$m->caption = isset($media['alt']) ? strip_tags($media['alt']) : null;
			$m->order = isset($media['cursor']) && is_int($media['cursor']) ? (int) $media['cursor'] : $k;
			// if($optimize_media == false) {
			// 	$m->skip_optimize = true;
			// 	ImageThumbnail::dispatch($m);
			// } else {
			// 	ImageOptimize::dispatch($m);
			// }
			if($cw == true || $profile->cw == true) {
				$m->is_nsfw = $cw;
				$status->is_nsfw = $cw;
			}
			$m->save();
			$attachments[] = $m;
			array_push($mimes, $m->mime);
		}

		$mediaType = StatusController::mimeTypeCheck($mimes);

		if(in_array($mediaType, ['photo', 'video', 'photo:album']) == false) {
			abort(400, __('exception.compose.invalid.album'));
		}

		if($place && is_array($place)) {
			$status->place_id = $place['id'];
		}

		if($request->filled('comments_disabled')) {
			$status->comments_disabled = (bool) $request->input('comments_disabled');
		}

		$status->caption = strip_tags($request->caption);
		$status->rendered = Autolink::create()->autolink($status->caption);
		$status->scope = 'draft';
		$status->profile_id = $profile->id;
		$status->save();

		foreach($attachments as $media) {
			$media->status_id = $status->id;
			$media->save();
		}

		$visibility = $profile->unlisted == true && $visibility == 'public' ? 'unlisted' : $visibility;
		$visibility = $profile->is_private ? 'private' : $visibility;
		$cw = $profile->cw == true ? true : $cw;
		$status->is_nsfw = $cw;
		$status->visibility = $visibility;
		$status->scope = $visibility;
		$status->type = $mediaType;
		$status->save();

		foreach($tagged as $tg) {
			$mt = new MediaTag;
			$mt->status_id = $status->id;
			$mt->media_id = $status->media->first()->id;
			$mt->profile_id = $tg['id'];
			$mt->tagged_username = $tg['name'];
			$mt->is_public = true;
			$mt->metadata = json_encode([
				'_v' => 1,
			]);
			$mt->save();
			MediaTagService::set($mt->status_id, $mt->profile_id);
			MediaTagService::sendNotification($mt);
		}

		NewStatusPipeline::dispatch($status);
		Cache::forget('user:account:id:'.$profile->user_id);
		Cache::forget('_api:statuses:recent_9:'.$profile->id);
		Cache::forget('profile:status_count:'.$profile->id);
		Cache::forget('status:transformer:media:attachments:'.$status->id);
		Cache::forget($user->storageUsedKey());
		Cache::forget('profile:embed:' . $status->profile_id);
		Cache::forget($limitKey);

		return $status->url();
	}

	public function storeText(Request $request)
	{
		abort_unless(config('exp.top'), 404);
		$this->validate($request, [
			'caption' => 'nullable|string|max:'.config('pixelfed.max_caption_length', 500),
			'cw' => 'nullable|boolean',
			'visibility' => 'required|string|in:public,private,unlisted|min:2|max:10',
			'place' => 'nullable',
			'comments_disabled' => 'nullable',
			'tagged' => 'nullable',
		]);

		if(config('costar.enabled') == true) {
			$blockedKeywords = config('costar.keyword.block');
			if($blockedKeywords !== null && $request->caption) {
				$keywords = config('costar.keyword.block');
				foreach($keywords as $kw) {
					if(Str::contains($request->caption, $kw) == true) {
						abort(400, 'Invalid object');
					}
				}
			}
		}

		$user = Auth::user();
		$profile = $user->profile;
		$visibility = $request->input('visibility');
		$status = new Status;
		$place = $request->input('place');
		$cw = $request->input('cw');
		$tagged = $request->input('tagged');

		if($place && is_array($place)) {
			$status->place_id = $place['id'];
		}

		if($request->filled('comments_disabled')) {
			$status->comments_disabled = (bool) $request->input('comments_disabled');
		}

		$status->caption = strip_tags($request->caption);
		$status->profile_id = $profile->id;
		$entities = Extractor::create()->extract($status->caption);
		$visibility = $profile->unlisted == true && $visibility == 'public' ? 'unlisted' : $visibility;
		$cw = $profile->cw == true ? true : $cw;
		$status->is_nsfw = $cw;
		$status->visibility = $visibility;
		$status->scope = $visibility;
		$status->type = 'text';
		$status->rendered = Autolink::create()->autolink($status->caption);
		$status->entities = json_encode(array_merge([
			'timg' => [
				'version' => 0,
				'bg_id' => 1,
				'font_size' => strlen($status->caption) <= 140 ? 'h1' : 'h3',
				'length' => strlen($status->caption),
			]
		], $entities), JSON_UNESCAPED_SLASHES);
		$status->save();

		foreach($tagged as $tg) {
			$mt = new MediaTag;
			$mt->status_id = $status->id;
			$mt->media_id = $status->media->first()->id;
			$mt->profile_id = $tg['id'];
			$mt->tagged_username = $tg['name'];
			$mt->is_public = true;
			$mt->metadata = json_encode([
				'_v' => 1,
			]);
			$mt->save();
			MediaTagService::set($mt->status_id, $mt->profile_id);
			MediaTagService::sendNotification($mt);
		}


		Cache::forget('user:account:id:'.$profile->user_id);
		Cache::forget('_api:statuses:recent_9:'.$profile->id);
		Cache::forget('profile:status_count:'.$profile->id);

		return $status->url();
	}

	public function mediaProcessingCheck(Request $request)
	{
		$this->validate($request, [
			'id' => 'required|integer|min:1'
		]);

		$media = Media::whereUserId($request->user()->id)
			->whereNull('status_id')
			->findOrFail($request->input('id'));

		if(config('pixelfed.media_fast_process')) {
			return [
				'finished' => true
			];
		}

		$finished = false;

		switch ($media->mime) {
			case 'image/jpeg':
			case 'image/png':
			case 'video/mp4':
				$finished = config('pixelfed.cloud_storage') ? (bool) $media->cdn_url : (bool) $media->processed_at;
				break;
			
			default:
				# code...
				break;
		}

		return [
			'finished' => $finished
		];
	}
}
