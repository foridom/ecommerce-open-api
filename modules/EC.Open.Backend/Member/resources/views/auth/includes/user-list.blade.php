<table class="table table-striped table-bordered table-hover">
    <thead>
    <tr>
        <th>ID</th>
        {{--<th>登录名</th>--}}
        <th>昵称</th>
        {{--<th>邮箱</th>--}}
        <th>电话</th>
        <th>积分</th>
        <th>角色</th>
        {{--<th>用户已确认</th>--}}
        {{--<th>角色</th>--}}
        <th class="visible-lg">注册时间</th>
        {{--<th class="visible-lg">更新时间</th>--}}
        <th>操作</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($users as $user)
        <tr>
            <td>{!! $user->id !!}</td>
            {{--<td>{!! $user->name !!}</td>--}}
            <td>{!! $user->nick_name !!}</td>
            {{--<td>{!! link_to("mailto:".$user->email, $user->email) !!}</td>--}}
            <td>{!! $user->mobile !!}</td>
            <td>{!! $user->available_integral !!}</td>
            <td>
                {{ implode(',' , $user->roles->pluck('display_name')->toArray()) }}
            </td>

            {{--<td>{!! $user->confirmed_label !!}</td>--}}
            {{--<td>--}}
                {{--@if ($user->roles()->count() > 0)--}}
                    {{--@foreach ($user->roles as $role)--}}
                        {{--{!! $role->display_name !!}<br/>--}}
                    {{--@endforeach--}}
                {{--@else--}}
                    {{--None--}}
            {{--@endif--}}
            <td class="visible-lg">{!! $user->created_at !!}</td>
            {{--<td class="visible-lg">{!! $user->updated_at !!}</td>--}}

            <td>
                @if(empty($user->deleted_at))
                {!! $user->action_buttons !!}
                {{--<a target="_blank" href="{{route('backend.test.user',['id'=>$user->id])}}" class="btn btn-xs btn-primary">--}}
                    {{--模拟登陆--}}
                {{--</a>--}}
               @endif
            </td>


        </tr>
    @endforeach
    </tbody>
</table>