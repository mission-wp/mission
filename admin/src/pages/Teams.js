import TeamList from './teams/TeamList';
import TeamDetail from './teams/TeamDetail';

export default function Teams() {
  const params = new URLSearchParams( window.location.search );
  const teamId = params.get( 'team_id' );

  if ( teamId ) {
    return <TeamDetail id={ Number( teamId ) } />;
  }

  return <TeamList />;
}
