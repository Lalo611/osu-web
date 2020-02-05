<?php

/**
 *    Copyright (c) ppy Pty Ltd <contact@ppy.sh>.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Libraries;

use App\Exceptions\InvariantException;
use App\Models\BeatmapDiscussion;
use App\Models\BeatmapDiscussionPost;
use Auth;
use DB;

class BeatmapDiscussionReview
{
    public static function create($beatmapset, $document)
    {
        if (!$document || !is_array($document) || empty($document)) {
            throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_document'));
        }

        $output = [];
        try {
            DB::beginTransaction();

            // create the issues for the embeds first
            $childIds = [];
            $blockCount = 0;

            foreach ($document as $block) {
                if (!isset($block['type'])) {
                    throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }

                switch ($block['type']) {
                    case 'embed':
                        $message = $block['text'];
                        $beatmapId = $block['beatmap_id'] ?? null;

                        $discussion = new BeatmapDiscussion([
                            'beatmapset_id' => $beatmapset->getKey(),
                            'user_id' => Auth::user()->getKey(),
                            'resolved' => false,
//                            'timestamp' => $block['timestamp'],
                            'message_type' => $block['discussion_type'],
                            'beatmap_id' => $beatmapId,
                        ]);
                        $discussion->saveOrExplode();

                        $postParams = [
                            'user_id' => Auth::user()->user_id,
                            'message' => $message,
                        ];
                        $post = new BeatmapDiscussionPost($postParams);
                        $post->beatmapDiscussion()->associate($discussion);
                        $post->saveOrExplode();

                        $issues[] = [
                            'discussion' => $discussion->getKey(),
                            'post' => $post->getKey(),
                        ];
                        $childIds[] = $discussion->getKey();
                        break;
                }
                $blockCount++;
            }

            $minIssues = config('osu.beatmapset.discussion_review_min_issues');
            if (empty($childIds) || count($childIds) < $minIssues) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.minimum_issues', $minIssues));
            }

            $maxBlocks = config('osu.beatmapset.discussion_review_max_blocks');
            if ($blockCount > $maxBlocks) {
                throw new InvariantException(trans_choice('beatmap_discussions.review.validation.too_many_blocks', $maxBlocks));
            }

            // generate the post body now that the issues have been created
            foreach ($document as $block) {
                switch ($block['type']) {
                    case 'paragraph':
                        if (!$block['text']) {
                            throw new InvariantException(trans('beatmap_discussions.review.validation.paragraph_missing_text'));
                        }
                        // escape embed injection attempts
                        $text = preg_replace('/%\[\]\(#(\d+)\)/', '%\[\]\(#$1\)', $block['text']);
                        $output[] = "{$text}\n";
                        break;

                    case 'embed':
                        $discussionId = array_shift($issues)['discussion'];
                        $output[] = "%[](#{$discussionId})\n";
                        break;

                    default:
                        // invalid block type
                        throw new InvariantException(trans('beatmap_discussions.review.validation.invalid_block_type'));
                }
            }

            // create the review post
            $review = new BeatmapDiscussion([
                'beatmapset_id' => $beatmapset->getKey(),
                'user_id' => Auth::user()->getKey(),
                'resolved' => false,
                'message_type' => 'review',
            ]);
            $review->saveOrExplode();
            $post = new BeatmapDiscussionPost([
                'user_id' => Auth::user()->user_id,
                'message' => implode('', $output),
            ]);
            $post->beatmapDiscussion()->associate($review);
            $post->saveOrExplode();

            // associate children with parent
            BeatmapDiscussion::whereIn('id', $childIds)
                ->update(['parent_id' => $review->getKey()]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
